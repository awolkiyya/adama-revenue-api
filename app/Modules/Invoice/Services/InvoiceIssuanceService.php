<?php

namespace App\Modules\Invoice\Services;

use App\Models\Assessment;
use App\Models\Invoice;
use App\Services\SmsService;
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
        | 1. CREATE INVOICE
        |--------------------------------------------------------------------------
        |
        | InvoiceService owns invoice construction.
        |
        */

        $invoice = $this->invoiceService->createFromAssessment(
            $assessment
        );

        /*
        |--------------------------------------------------------------------------
        | 2. ISSUE INVOICE
        |--------------------------------------------------------------------------
        |
        | Pass the Invoice MODEL.
        |
        | issue() explicitly supports:
        |
        |     Invoice|string
        |
        | and safely extracts the primary key.
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
        | IMPORTANT:
        |
        | Never pass the complete Invoice model/object to:
        |
        |     where('id', ...)
        |
        | PostgreSQL expects a UUID here.
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
        | The authenticated officer becomes the issuer.
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

        $issuedInvoice = DB::transaction(
            function () use ($invoiceId, $userId): Invoice {

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
                | If the invoice has already been issued, do not issue it again.
                |
                */

                if ($invoice->status === 'ISSUED') {
                    return $invoice;
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

                return $invoice->fresh([
                    'items',
                    'assessment',
                    'citizen',
                ]);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | 5. NOTIFY TAXPAYER
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The database transaction has already committed.
        |
        | Therefore SMS failure MUST NOT rollback:
        |
        |     ISSUED → DRAFT
        |
        | The invoice remains legally issued.
        |
        */

        $this->sendInvoiceNotification(
            $issuedInvoice
        );

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
    | The SMS contains:
    |
    | - taxpayer name
    | - invoice number
    | - amount due
    | - mobile application URL
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

            $phone = $citizen->phone;

            if (! $phone) {

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
            | 3. TAXPAYER NAME
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
                (float) $invoice->total_amount,
                2,
                '.',
                ','
            );

            /*
            |--------------------------------------------------------------------------
            | 5. MOBILE APPLICATION URL
            |--------------------------------------------------------------------------
            |
            | Configure this in .env:
            |
            | TAXPAYER_APP_URL=https://your-domain.com/en
            |
            | For local development:
            |
            | TAXPAYER_APP_URL=http://localhost:3000/en
            |
            | IMPORTANT:
            |
            | localhost only works from the same machine.
            | It should NOT be used for real taxpayer SMS.
            |
            */

            $appUrl = trim(
                (string) config(
                    'app.taxpayer_app_url',
                    env(
                        'TAXPAYER_APP_URL',
                        'http://localhost:3000/en'
                    )
                )
            );

            /*
            |--------------------------------------------------------------------------
            | 6. BUILD SMS
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
                ($response['status'] ?? null) !== 'success'
            ) {

                Log::error(
                    'Invoice was issued but SMS notification failed.',
                    [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'citizen_id' => $citizen->id,
                        'phone' => $phone,
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
            | SMS failure must NEVER affect invoice issuance.
            |
            | Invoice remains:
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
        $citizen
    ): string {

        /*
        |--------------------------------------------------------------------------
        | 1. FIRST/MIDDLE/LAST NAME
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
        |
        | Your current Citizen model contains:
        |
        |     full_name
        |
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
