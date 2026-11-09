<?php

declare(strict_types=1);

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
    | INVOICE SOURCE TYPES
    |--------------------------------------------------------------------------
    */

    private const SOURCE_ASSESSMENT = 'ASSESSMENT';

    private const SOURCE_DIRECT_COLLECTION = 'DIRECT_COLLECTION';

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
    */

    public function issueFromAssessment(
        Assessment $assessment
    ): Invoice {
        if ($assessment->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'assessment' => [
                    'Only approved assessments can generate an invoice.',
                ],
            ]);
        }

        $invoice = $this->invoiceService->createFromAssessment(
            $assessment
        );

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
        */

        $invoiceId = $invoice instanceof Invoice
            ? $invoice->getKey()
            : $invoice;

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
        | 2. GET AUTHENTICATED USER
        |--------------------------------------------------------------------------
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
        | 3. ISSUE IN TRANSACTION
        |--------------------------------------------------------------------------
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
                | An already-issued invoice must not be issued again
                | or trigger another SMS notification.
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
                | VALIDATE SOURCE TYPE
                |--------------------------------------------------------------------------
                |
                | Only explicitly supported invoice sources can be issued
                | through this service.
                |
                */

                $sourceType = strtoupper(
                    trim((string) $invoice->source_type)
                );

                if (! in_array(
                    $sourceType,
                    [
                        self::SOURCE_ASSESSMENT,
                        self::SOURCE_DIRECT_COLLECTION,
                    ],
                    true
                )) {
                    throw ValidationException::withMessages([
                        'source_type' => [
                            'This invoice source type cannot be issued.',
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
                | VALIDATE EACH INVOICE ITEM
                |--------------------------------------------------------------------------
                */

                foreach ($invoice->items as $item) {

                    if (
                        $item->amount === null
                        || ! is_numeric($item->amount)
                        || ! is_finite((float) $item->amount)
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
                        || ! is_numeric($item->total_amount)
                        || ! is_finite((float) $item->total_amount)
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
                | VALIDATE INVOICE TOTAL
                |--------------------------------------------------------------------------
                */

                if (
                    $invoice->total_amount === null
                    || ! is_numeric($invoice->total_amount)
                    || ! is_finite((float) $invoice->total_amount)
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
                | VALIDATE PAID AMOUNT
                |--------------------------------------------------------------------------
                */

                if (
                    $invoice->paid_amount !== null
                    && (
                        ! is_numeric($invoice->paid_amount)
                        || ! is_finite((float) $invoice->paid_amount)
                        || (float) $invoice->paid_amount < 0
                    )
                ) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'Invoice paid amount cannot be negative or invalid.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE OUTSTANDING BALANCE
                |--------------------------------------------------------------------------
                */

                if (
                    $invoice->balance_due === null
                    || ! is_numeric($invoice->balance_due)
                    || ! is_finite((float) $invoice->balance_due)
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
                | Assessment invoices require a due date.
                |
                | Direct collection invoices are collected immediately
                | through the field collection workflow and do not
                | require a due date.
                |
                */

                if (
                    $sourceType === self::SOURCE_ASSESSMENT
                    && ! $invoice->due_date
                ) {
                    throw ValidationException::withMessages([
                        'due_date' => [
                            'An assessment invoice must have a due date before it can be issued.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | VALIDATE ASSESSMENT ASSOCIATION
                |--------------------------------------------------------------------------
                |
                | Only assessment invoices must belong to an approved
                | assessment.
                |
                */

                if ($sourceType === self::SOURCE_ASSESSMENT) {

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
                | VALIDATE DIRECT COLLECTION
                |--------------------------------------------------------------------------
                |
                | Direct collection invoices must not have an assessment
                | association or a due date.
                |
                */

                if ($sourceType === self::SOURCE_DIRECT_COLLECTION) {

                    if ($invoice->assessment_id !== null) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'A direct collection invoice cannot belong to an assessment.',
                            ],
                        ]);
                    }

                    if ($invoice->due_date !== null) {
                        throw ValidationException::withMessages([
                            'due_date' => [
                                'A direct collection invoice must not have a due date.',
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
        | 4. SEND NOTIFICATION AFTER TRANSACTION
        |--------------------------------------------------------------------------
        */

        if ($result['should_notify']) {
            $this->sendInvoiceNotification(
                $issuedInvoice
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 5. RETURN ISSUED INVOICE
        |--------------------------------------------------------------------------
        */

        return $issuedInvoice;
    }

    /*
    |--------------------------------------------------------------------------
    | SEND INVOICE SMS
    |--------------------------------------------------------------------------
    */

    protected function sendInvoiceNotification(
        Invoice $invoice
    ): void {
        try {

            /*
            |--------------------------------------------------------------------------
            | LOAD TAXPAYER
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
            | GET PHONE
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
            | GET TAXPAYER NAME
            |--------------------------------------------------------------------------
            */

            $taxpayerName = $this->getTaxpayerName(
                $citizen
            );

            /*
            |--------------------------------------------------------------------------
            | FORMAT AMOUNT
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
            | GET TAXPAYER APPLICATION URL
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
            | BUILD SMS MESSAGE
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
            | SEND SMS
            |--------------------------------------------------------------------------
            */

            $response = $this->smsService->sendByPhone(
                phone: $phone,
                message: $message,
            );

            /*
            |--------------------------------------------------------------------------
            | CHECK SMS RESPONSE
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
            | SUCCESS LOG
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
            | SMS FAILURE MUST NOT ROLLBACK INVOICE ISSUANCE
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
        | FIRST / MIDDLE / LAST NAME
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
        | FULL NAME FALLBACK
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
        | FINAL FALLBACK
        |--------------------------------------------------------------------------
        */

        return $name !== ''
            ? $name
            : 'Taxpayer';
    }
}
