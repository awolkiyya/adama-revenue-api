<?php

namespace App\Modules\Invoice\Services;

use App\Models\Assessment;
use App\Models\Invoice;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InvoiceIssuanceService
{
    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        protected InvoiceService $invoiceService,
        protected SmsService $smsService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | ISSUE INVOICE FROM APPROVED ASSESSMENT
    |--------------------------------------------------------------------------
    |
    | Business flow:
    |
    | PENDING_APPROVAL
    |        ↓
    |     APPROVED
    |        ↓
    |   CREATE INVOICE
    |        ↓
    |      DRAFT
    |        ↓
    |      ISSUED
    |        ↓
    |   SMS TAXPAYER
    |
    |--------------------------------------------------------------------------
    |
    | Responsibilities:
    |
    | InvoiceService
    |     - creates invoice
    |     - creates invoice items
    |     - copies financial snapshots
    |     - calculates invoice totals from existing assessment results
    |
    | InvoiceIssuanceService
    |     - validates invoice state
    |     - locks invoice
    |     - validates issue conditions
    |     - issues invoice
    |     - records issued_by
    |     - records issued_at
    |     - notifies taxpayer
    |
    | No tariff calculation is performed here.
    |
    |--------------------------------------------------------------------------
    */

    public function issueFromAssessment(
        Assessment $assessment
    ): Invoice {
        /*
        |--------------------------------------------------------------------------
        | 1. VALIDATE ASSESSMENT
        |--------------------------------------------------------------------------
        |
        | Only approved assessments can produce an issued invoice.
        |
        */

        if ($assessment->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'assessment' => [
                    'Only approved assessments can generate an invoice.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. CREATE INVOICE
        |--------------------------------------------------------------------------
        |
        | InvoiceService owns invoice construction.
        |
        | It creates the invoice in DRAFT state.
        |
        */

        $invoice = $this->invoiceService->createFromAssessment(
            $assessment
        );

        /*
        |--------------------------------------------------------------------------
        | 3. ISSUE INVOICE
        |--------------------------------------------------------------------------
        |
        | Pass the Invoice model.
        |
        */

        return $this->issue($invoice);
    }

    /*
    |--------------------------------------------------------------------------
    | ISSUE EXISTING DRAFT INVOICE
    |--------------------------------------------------------------------------
    |
    | Accepts:
    |
    |     Invoice model
    |
    | OR
    |
    |     Invoice ID string
    |
    |--------------------------------------------------------------------------
    */

    public function issue(
        Invoice|string $invoice
    ): Invoice {
        /*
        |--------------------------------------------------------------------------
        | 1. NORMALIZE INVOICE ID
        |--------------------------------------------------------------------------
        |
        | Never pass the complete Invoice model to:
        |
        |     where('id', ...)
        |
        | PostgreSQL expects the UUID value.
        |
        | Therefore:
        |
        |     Invoice model → getKey()
        |     string         → use directly
        |
        */

        $invoiceId = $invoice instanceof Invoice
            ? $invoice->getKey()
            : $invoice;

        /*
        |--------------------------------------------------------------------------
        | 2. VALIDATE INVOICE ID
        |--------------------------------------------------------------------------
        */

        if (
            ! is_string($invoiceId)
            || trim($invoiceId) === ''
        ) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'A valid invoice ID is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. GET AUTHENTICATED USER
        |--------------------------------------------------------------------------
        |
        | The authenticated officer becomes the invoice issuer.
        |
        */

        $userId = Auth::id();

        if (! $userId) {
            throw ValidationException::withMessages([
                'authorization' => [
                    'An authenticated user is required to issue an invoice.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. ISSUE IN TRANSACTION
        |--------------------------------------------------------------------------
        |
        | The invoice row is locked to prevent two officers from issuing
        | the same invoice concurrently.
        |
        */

        $result = DB::transaction(
            function () use ($invoiceId, $userId): array {

                /*
                |--------------------------------------------------------------------------
                | LOAD + LOCK INVOICE
                |--------------------------------------------------------------------------
                */

                $invoice = Invoice::query()
                    ->with([
                        'items',
                        'assessment',
                        'citizen',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($invoiceId);

                /*
                |--------------------------------------------------------------------------
                | IDEMPOTENCY
                |--------------------------------------------------------------------------
                |
                | If the invoice is already issued, return it without
                | sending another notification.
                |
                */

                if ($invoice->status === 'ISSUED') {
                    return [
                        'invoice' => $invoice,
                        'should_notify' => false,
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE STATUS
                |--------------------------------------------------------------------------
                */

                if ($invoice->status !== 'DRAFT') {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Only draft invoices can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE TAXPAYER ID
                |--------------------------------------------------------------------------
                */

                if (! $invoice->citizen_id) {
                    throw ValidationException::withMessages([
                        'citizen' => [
                            'A taxpayer is required before an invoice can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE TAXPAYER RELATION
                |--------------------------------------------------------------------------
                */

                if (! $invoice->citizen) {
                    throw ValidationException::withMessages([
                        'citizen' => [
                            'The taxpayer associated with this invoice could not be found.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE INVOICE ITEMS
                |--------------------------------------------------------------------------
                |
                | An invoice without line items must never be issued.
                |
                */

                if ($invoice->items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'items' => [
                            'An invoice must contain at least one item before it can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE INVOICE ITEMS
                |--------------------------------------------------------------------------
                */

                foreach ($invoice->items as $item) {

                    if (
                        $item->amount === null
                        || (float) $item->amount < 0
                    ) {
                        throw ValidationException::withMessages([
                            'items' => [
                                'Every invoice item must have a valid amount.',
                            ],
                        ]);
                    }

                    if (
                        $item->total_amount === null
                        || (float) $item->total_amount < 0
                    ) {
                        throw ValidationException::withMessages([
                            'items' => [
                                'Every invoice item must have a valid total amount.',
                            ],
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE TOTAL
                |--------------------------------------------------------------------------
                */

                if (
                    $invoice->total_amount === null
                    || (float) $invoice->total_amount <= 0
                ) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'An invoice must have a positive total amount before it can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE BALANCE
                |--------------------------------------------------------------------------
                |
                | A newly issued invoice should have the full amount outstanding.
                |
                */

                if (
                    $invoice->paid_amount !== null
                    && (float) $invoice->paid_amount < 0
                ) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'Invoice paid amount cannot be negative.',
                        ],
                    ]);
                }

                if (
                    $invoice->balance_due === null
                    || (float) $invoice->balance_due <= 0
                ) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'An invoice must have a positive outstanding balance before it can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE DUE DATE
                |--------------------------------------------------------------------------
                |
                | The due date should already have been resolved by the
                | assessment/calculation layer.
                |
                */

                if (! $invoice->due_date) {
                    throw ValidationException::withMessages([
                        'due_date' => [
                            'An invoice must have a due date before it can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE ASSESSMENT
                |--------------------------------------------------------------------------
                |
                | Assessment-generated invoices must remain associated
                | with an approved assessment.
                |
                */

                if ($invoice->source_type === 'ASSESSMENT') {

                    if (! $invoice->assessment_id) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'An assessment is required for an assessment invoice.',
                            ],
                        ]);
                    }

                    if (! $invoice->assessment) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'The assessment associated with this invoice could not be found.',
                            ],
                        ]);
                    }

                    if ($invoice->assessment->status !== 'APPROVED') {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'Only invoices belonging to approved assessments can be issued.',
                            ],
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | ISSUE INVOICE
                |--------------------------------------------------------------------------
                */

                $invoice->update([
                    'status' => 'ISSUED',
                    'issued_at' => now(),
                    'issued_by' => $userId,
                ]);

                /*
                |--------------------------------------------------------------------------
                | RETURN FRESH INVOICE
                |--------------------------------------------------------------------------
                */

                $invoice = $invoice->fresh([
                    'items',
                    'assessment',
                    'citizen',
                ]);

                return [
                    'invoice' => $invoice,
                    'should_notify' => true,
                ];
            }
        );

        /** @var Invoice $issuedInvoice */
        $issuedInvoice = $result['invoice'];

        /*
        |--------------------------------------------------------------------------
        | 5. NOTIFY ONLY WHEN THIS REQUEST ACTUALLY ISSUED THE INVOICE
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | If another request already issued this invoice, the transaction
        | returns should_notify = false.
        |
        | Therefore this request will NOT send another SMS.
        |
        */

        if ($result['should_notify']) {
            $this->sendInvoiceNotification(
                $issuedInvoice
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 6. RETURN ISSUED INVOICE
        |--------------------------------------------------------------------------
        */

        return $issuedInvoice;
    }

    /*
    |--------------------------------------------------------------------------
    | SEND INVOICE SMS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | This method runs AFTER the database transaction commits.
    |
    | SMS failure therefore cannot rollback invoice issuance.
    |
    |--------------------------------------------------------------------------
    */

    protected function sendInvoiceNotification(
        Invoice $invoice
    ): void {
        try {

            /*
            |--------------------------------------------------------------------------
            | 1. LOAD TAXPAYER
            |--------------------------------------------------------------------------
            */

            $citizen = $invoice->citizen;

            if (! $citizen) {

                Log::warning(
                    'Invoice issued but taxpayer was not found for SMS notification.',
                    [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'citizen_id' => $invoice->citizen_id,
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | 2. GET PHONE
            |--------------------------------------------------------------------------
            */

            $phone = trim(
                (string) ($citizen->phone ?? '')
            );

            if ($phone === '') {

                Log::warning(
                    'Invoice issued but taxpayer has no phone number.',
                    [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'citizen_id' => $citizen->id,
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | 3. GET TAXPAYER NAME
            |--------------------------------------------------------------------------
            */

            $taxpayerName = $this->getTaxpayerName(
                $citizen
            );

            /*
            |--------------------------------------------------------------------------
            | 4. FORMAT AMOUNT
            |--------------------------------------------------------------------------
            */

            $amount = number_format(
                (float) $invoice->balance_due,
                2,
                '.',
                ','
            );

            /*
            |--------------------------------------------------------------------------
            | 5. GET TAXPAYER APPLICATION URL
            |--------------------------------------------------------------------------
            |
            | Configure:
            |
            | config/app.php
            |
            | 'taxpayer_app_url' => env(
            |     'TAXPAYER_APP_URL',
            |     'http://localhost:3000/en'
            | ),
            |
            |--------------------------------------------------------------------------
            */

            $appUrl = trim(
                (string) config(
                    'app.taxpayer_app_url',
                    'http://localhost:3000/en'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | 6. BUILD SMS MESSAGE
            |--------------------------------------------------------------------------
            */

            $message = sprintf(
                'Dear %s, your revenue invoice %s has been issued. Amount due: %s ETB. Please use invoice number %s for payment. Open the taxpayer app: %s',
                $taxpayerName,
                $invoice->invoice_number,
                $amount,
                $invoice->invoice_number,
                $appUrl
            );

            /*
            |--------------------------------------------------------------------------
            | 7. SEND SMS
            |--------------------------------------------------------------------------
            */

            $response = $this->smsService->sendByPhone(
                phone: $phone,
                message: $message,
            );

            /*
            |--------------------------------------------------------------------------
            | 8. CHECK SMS RESPONSE
            |--------------------------------------------------------------------------
            */

            if (
                ! is_array($response)
                || ($response['status'] ?? null) !== 'success'
            ) {

                Log::error(
                    'Invoice was issued but SMS notification failed.',
                    [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'citizen_id' => $citizen->id,
                        'sms_response' => $response,
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | 9. SUCCESS LOG
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Invoice SMS notification sent successfully.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'citizen_id' => $citizen->id,
                ]
            );

        } catch (\Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | SMS failure MUST NEVER affect invoice issuance.
            |
            | The invoice remains:
            |
            |     ISSUED
            |
            |--------------------------------------------------------------------------
            */

            Log::error(
                'Invoice issued successfully but SMS notification threw an exception.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'citizen_id' => $invoice->citizen_id,
                    'error' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET TAXPAYER NAME
    |--------------------------------------------------------------------------
    */

    protected function getTaxpayerName(
        Model $citizen
    ): string {
        /*
        |--------------------------------------------------------------------------
        | 1. FIRST / MIDDLE / LAST NAME
        |--------------------------------------------------------------------------
        */

        $name = trim(
            implode(
                ' ',
                array_filter([
                    $citizen->first_name ?? null,
                    $citizen->middle_name ?? null,
                    $citizen->last_name ?? null,
                ])
            )
        );

        /*
        |--------------------------------------------------------------------------
        | 2. FULL NAME FALLBACK
        |--------------------------------------------------------------------------
        */

        if ($name === '') {

            $name = trim(
                (string) (
                    $citizen->full_name
                    ?? $citizen->name
                    ?? ''
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 3. FINAL FALLBACK
        |--------------------------------------------------------------------------
        */

        return $name !== ''
            ? $name
            : 'Taxpayer';
    }
}
