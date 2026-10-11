<?php

namespace App\Modules\Payment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the payment into an API representation.
     *
     * Invoice items are the source of truth for services
     * actually billed on this invoice.
     *
     * Relationships are serialized only when loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $citizen = $this->relationLoaded('citizen')
            ? $this->citizen
            : null;

        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'transaction_reference' => $this->transaction_reference,
            'provider_reference' => $this->provider_reference,

            /*
            |--------------------------------------------------------------------------
            | Business References
            |--------------------------------------------------------------------------
            */

            'invoice_id' => $this->invoice_id,
            'citizen_id' => $this->citizen_id,

            /*
            |--------------------------------------------------------------------------
            | Payment Classification
            |--------------------------------------------------------------------------
            */

            'payment_method' => $this->enumValue(
                $this->payment_method
            ),

            'payment_provider' => $this->enumValue(
                $this->payment_provider
            ),

            'payment_source' => $this->enumValue(
                $this->payment_source
            ),

            'status' => $this->enumValue(
                $this->status
            ),

            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            'amount' => $this->decimalValue($this->amount),
            'currency' => $this->currency,

            /*
            |--------------------------------------------------------------------------
            | Payer Information
            |--------------------------------------------------------------------------
            |
            | Prefer the original payment snapshot. Fall back to the
            | citizen record for older payments with empty snapshots.
            |
            */

            'payer' => [
                'name' => $this->payer_name
                    ?: ($citizen?->full_name),

                'email' => $this->payer_email
                    ?: ($citizen?->email),

                'phone' => $this->payer_phone
                    ?: ($citizen?->phone),
            ],

            /*
            |--------------------------------------------------------------------------
            | Processing and Verification
            |--------------------------------------------------------------------------
            */

            'processed_by' => $this->processed_by,

            'processed_by_user' => $this->whenLoaded(
                'processedBy',
                fn () => $this->processedBy
                    ? [
                        'id' => $this->processedBy->id,
                        'name' => $this->processedBy->name,
                    ]
                    : null
            ),

            'verified_by' => $this->verified_by,

            'verified_by_user' => $this->whenLoaded(
                'verifiedBy',
                fn () => $this->verifiedBy
                    ? [
                        'id' => $this->verifiedBy->id,
                        'name' => $this->verifiedBy->name,
                    ]
                    : null
            ),

            'verified_at' => $this->dateValue(
                $this->verified_at
            ),

            /*
            |--------------------------------------------------------------------------
            | Failure Information
            |--------------------------------------------------------------------------
            */

            'failure_reason' => $this->failure_reason,

            /*
            |--------------------------------------------------------------------------
            | Application Metadata
            |--------------------------------------------------------------------------
            |
            | Expose only metadata appropriate for the authorized
            | audience. Do not store or expose secrets here.
            |
            */

            'metadata' => $this->metadata,

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice' => $this->whenLoaded(
                'invoice',
                function () {
                    $invoice = $this->invoice;

                    if (! $invoice) {
                        return null;
                    }

                    return [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'source_type' => $this->enumValue(
                            $invoice->source_type
                        ),

                        'status' => $this->enumValue(
                            $invoice->status
                        ),

                        'currency' => $invoice->currency,

                        'subtotal' => $this->decimalValue(
                            $invoice->subtotal
                        ),

                        'discount_amount' => $this->decimalValue(
                            $invoice->discount_amount
                        ),

                        'penalty_amount' => $this->decimalValue(
                            $invoice->penalty_amount
                        ),

                        'interest_amount' => $this->decimalValue(
                            $invoice->interest_amount
                        ),

                        'total_amount' => $this->decimalValue(
                            $invoice->total_amount
                        ),

                        'paid_amount' => $this->decimalValue(
                            $invoice->paid_amount
                        ),

                        'balance_due' => $this->decimalValue(
                            $invoice->balance_due
                        ),

                        'issued_at' => $this->dateValue(
                            $invoice->issued_at
                        ),

                        'due_date' => $this->dateValue(
                            $invoice->due_date
                        ),

                        /*
                        |--------------------------------------------------------------------------
                        | Invoice Items
                        |--------------------------------------------------------------------------
                        */

                        'items' => $invoice->relationLoaded('items')
                            ? $invoice->items
                                ->map(function ($item) {
                                    return [
                                        'id' => $item->id,
                                        'line_number' => $item->line_number,
                                        'description' => $item->description,
                                        'service_id' => $item->service_id,

                                        'quantity' => $this->decimalValue(
                                            $item->quantity
                                        ),

                                        'unit' => $item->unit,

                                        'unit_price' => $this->decimalValue(
                                            $item->unit_price
                                        ),

                                        'amount' => $this->decimalValue(
                                            $item->amount
                                        ),

                                        'discount_amount' => $this->decimalValue(
                                            $item->discount_amount
                                        ),

                                        'penalty_amount' => $this->decimalValue(
                                            $item->penalty_amount
                                        ),

                                        'interest_amount' => $this->decimalValue(
                                            $item->interest_amount
                                        ),

                                        'total_amount' => $this->decimalValue(
                                            $item->total_amount
                                        ),

                                        'currency' => $item->currency,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Billed Revenue Service
                                        |--------------------------------------------------------------------------
                                        */

                                        'service' => $item->relationLoaded('service')
                                            ? (
                                                $item->service
                                                    ? [
                                                        'id' => $item->service->id,
                                                        'name' => $item->service->name,
                                                        'description' => $item->service->description,
                                                        'service_type' => $this->enumValue(
                                                            $item->service->service_type
                                                        ),
                                                        'collection_mode' => $this->enumValue(
                                                            $item->service->collection_mode
                                                        ),

                                                        'revenue_code' => $item->service->relationLoaded('revenueCode')
                                                            ? (
                                                                $item->service->revenueCode
                                                                    ? [
                                                                        'id' => $item->service->revenueCode->id,
                                                                        'code' => $item->service->revenueCode->code ?? null,
                                                                        'name' => $item->service->revenueCode->name ?? null,
                                                                    ]
                                                                    : null
                                                            )
                                                            : null,
                                                    ]
                                                    : null
                                            )
                                            : null,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Assessment Service Context
                                        |--------------------------------------------------------------------------
                                        */

                                        'assessment_service' => $item->relationLoaded('assessmentService')
                                            ? (
                                                $item->assessmentService
                                                    ? [
                                                        'id' => $item->assessmentService->id,
                                                        'assessment_id' => $item->assessmentService->assessment_id,
                                                        'service_id' => $item->assessmentService->service_id,

                                                        'status' => $this->enumValue(
                                                            $item->assessmentService->status
                                                        ),

                                                        'computed_amount' => $this->decimalValue(
                                                            $item->assessmentService->computed_amount
                                                        ),

                                                        'paid_amount' => $this->decimalValue(
                                                            $item->assessmentService->paid_amount
                                                        ),

                                                        'remaining_amount' => $this->decimalValue(
                                                            $item->assessmentService->remaining_amount
                                                        ),

                                                        'payment_status' => $this->enumValue(
                                                            $item->assessmentService->payment_status
                                                        ),

                                                        'assessment' => $item->assessmentService->relationLoaded('assessment')
                                                            ? (
                                                                $item->assessmentService->assessment
                                                                    ? [
                                                                        'id' => $item->assessmentService->assessment->id,
                                                                        'assessment_number' => $item->assessmentService->assessment->assessment_number,

                                                                        'status' => $this->enumValue(
                                                                            $item->assessmentService->assessment->status
                                                                        ),

                                                                        'source_type' => $this->enumValue(
                                                                            $item->assessmentService->assessment->source_type
                                                                        ),
                                                                    ]
                                                                    : null
                                                            )
                                                            : null,
                                                    ]
                                                    : null
                                            )
                                            : null,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Payment Schedule Context
                                        |--------------------------------------------------------------------------
                                        |
                                        | Only fields confirmed by the current
                                        | resource are returned here.
                                        |
                                        */

                                        'payment_schedule' => $item->relationLoaded('paymentSchedule')
                                            ? (
                                                $item->paymentSchedule
                                                    ? [
                                                        'id' => $item->paymentSchedule->id,

                                                        'status' => $this->enumValue(
                                                            $item->paymentSchedule->status
                                                        ),
                                                    ]
                                                    : null
                                            )
                                            : null,
                                    ];
                                })
                                ->values()
                            : null,

                        /*
                        |--------------------------------------------------------------------------
                        | Invoice Taxpayer
                        |--------------------------------------------------------------------------
                        |
                        | Citizen uses full_name, not name.
                        |
                        */

                        'citizen' => $invoice->relationLoaded('citizen')
                            ? (
                                $invoice->citizen
                                    ? [
                                        'id' => $invoice->citizen->id,
                                        'citizen_uid' => $invoice->citizen->citizen_uid,
                                        'name' => $invoice->citizen->full_name,
                                        'full_name' => $invoice->citizen->full_name,
                                        'phone' => $invoice->citizen->phone,
                                        'email' => $invoice->citizen->email,
                                    ]
                                    : null
                            )
                            : null,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Payment-Level Citizen
            |--------------------------------------------------------------------------
            |
            | Citizen uses full_name, not name.
            |
            */

            'citizen' => $this->whenLoaded(
                'citizen',
                fn () => $this->citizen
                    ? [
                        'id' => $this->citizen->id,
                        'citizen_uid' => $this->citizen->citizen_uid,
                        'name' => $this->citizen->full_name,
                        'full_name' => $this->citizen->full_name,
                        'phone' => $this->citizen->phone,
                        'email' => $this->citizen->email,
                        'national_id' => $this->citizen->national_id,
                    ]
                    : null
            ),

            /*
            |--------------------------------------------------------------------------
            | Cash Payment Details
            |--------------------------------------------------------------------------
            */

            'cash_details' => $this->whenLoaded(
                'cashDetails',
                function () {
                    $cash = $this->cashDetails;

                    if (! $cash) {
                        return null;
                    }

                    return [
                        'id' => $cash->id,

                        'cash_received_at' => $this->dateValue(
                            $cash->cash_received_at
                        ),

                        'cashier_session_id' => $cash->cashier_session_id,
                        'received_by' => $cash->received_by,

                        'received_by_user' => $cash->relationLoaded('receivedBy')
                            ? (
                                $cash->receivedBy
                                    ? [
                                        'id' => $cash->receivedBy->id,
                                        'name' => $cash->receivedBy->name,
                                    ]
                                    : null
                            )
                            : null,

                        'notes' => $cash->notes,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Bank Transfer Details
            |--------------------------------------------------------------------------
            */

            'bank_transfer_details' => $this->whenLoaded(
                'bankTransferDetails',
                function () {
                    $bank = $this->bankTransferDetails;

                    if (! $bank) {
                        return null;
                    }

                    return [
                        'id' => $bank->id,
                        'bank_account_id' => $bank->bank_account_id,
                        'transfer_reference' => $bank->transfer_reference,

                        'transfer_date' => $this->dateValue(
                            $bank->transfer_date
                        ),

                        'sender_name' => $bank->sender_name,

                        /*
                         * Restrict sender account details according
                         * to the application's authorization policy.
                         */
                        'sender_account' => $bank->sender_account,

                        'verification_status' => $this->enumValue(
                            $bank->verification_status
                        ),

                        'verified_by' => $bank->verified_by,

                        'verified_at' => $this->dateValue(
                            $bank->verified_at
                        ),

                        'notes' => $bank->notes,

                        'verified_by_user' => $bank->relationLoaded('verifiedBy')
                            ? (
                                $bank->verifiedBy
                                    ? [
                                        'id' => $bank->verifiedBy->id,
                                        'name' => $bank->verifiedBy->name,
                                    ]
                                    : null
                            )
                            : null,

                        'bank_account' => $bank->relationLoaded('bankAccount')
                            ? (
                                $bank->bankAccount
                                    ? [
                                        'id' => $bank->bankAccount->id,
                                        'bank_name' => $bank->bankAccount->bank_name,
                                        'account_name' => $bank->bankAccount->account_name,
                                        'currency' => $bank->bankAccount->currency,
                                    ]
                                    : null
                            )
                            : null,

                        /*
                        |--------------------------------------------------------------------------
                        | Bank Transfer Evidence
                        |--------------------------------------------------------------------------
                        |
                        | Never expose storage_path or direct private
                        | storage URLs. Use authorized file-opening routes.
                        |
                        */

                        'files' => $bank->relationLoaded('files')
                            ? $bank->files
                                ->map(
                                    fn ($file) => $this->fileMetadata($file)
                                )
                                ->values()
                            : null,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Online Payment Details
            |--------------------------------------------------------------------------
            */

            'online_details' => $this->whenLoaded(
                'onlineDetails',
                function () {
                    $online = $this->onlineDetails;

                    if (! $online) {
                        return null;
                    }

                    $paymentStatus = $this->enumValue($this->status);

                    /*
                     * Do not expose a checkout URL for completed payments.
                     * A completed payment should be verified through its
                     * payment status and receipt, not paid again.
                     */
                    $checkoutUrl = in_array(
                        $paymentStatus,
                        ['PENDING', 'PROCESSING'],
                        true
                    )
                        ? $online->checkout_url
                        : null;

                    return [
                        'id' => $online->id,
                        'payment_provider_id' => $online->payment_provider_id,
                        'checkout_reference' => $online->checkout_reference,
                        'provider_transaction_id' => $online->provider_transaction_id,
                        'checkout_url' => $checkoutUrl,
                        'provider_status' => $online->provider_status,

                        'callback_received_at' => $this->dateValue(
                            $online->callback_received_at
                        ),

                        'paid_at' => $this->dateValue(
                            $online->paid_at
                        ),

                        'payment_provider' => $online->relationLoaded('paymentProvider')
                            ? (
                                $online->paymentProvider
                                    ? [
                                        'id' => $online->paymentProvider->id,
                                        'code' => $online->paymentProvider->code ?? null,
                                        'name' => $online->paymentProvider->name ?? null,
                                    ]
                                    : null
                            )
                            : null,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt' => $this->whenLoaded(
                'receipt',
                function () {
                    $receipt = $this->receipt;

                    if (! $receipt) {
                        return null;
                    }

                    return [
                        'id' => $receipt->id,
                        'receipt_number' => $receipt->receipt_number,

                        'issued_at' => $this->dateValue(
                            $receipt->issued_at
                        ),

                        'issued_by' => $receipt->issued_by,

                        'issued_by_user' => $receipt->relationLoaded('issuedBy')
                            ? (
                                $receipt->issuedBy
                                    ? [
                                        'id' => $receipt->issuedBy->id,
                                        'name' => $receipt->issuedBy->name,
                                    ]
                                    : null
                            )
                            : null,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Payment-Level Files
            |--------------------------------------------------------------------------
            |
            | Bank transfer evidence remains under
            | bank_transfer_details.files.
            |
            */

            'files' => $this->whenLoaded(
                'files',
                fn () => $this->files
                    ->map(
                        fn ($file) => $this->fileMetadata($file)
                    )
                    ->values()
            ),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->dateValue(
                $this->created_at
            ),

            'updated_at' => $this->dateValue(
                $this->updated_at
            ),
        ];
    }

    /**
     * Safely convert a PHP enum to its API value.
     */
    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    /**
     * Convert numeric database values to JSON numbers.
     */
    private function decimalValue(mixed $value): ?float
    {
        return $value !== null
            ? (float) $value
            : null;
    }

    /**
     * Serialize date values consistently.
     */
    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        return (string) $value;
    }

    /**
     * Return safe metadata for an attached file.
     *
     * Private storage paths and direct storage URLs are intentionally
     * excluded. Use the application's authorized file-opening flow.
     */
    private function fileMetadata(mixed $file): array
    {
        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'category' => $file->category,
            'visibility' => $file->visibility,

            'created_at' => $this->dateValue(
                $file->created_at
            ),

            'updated_at' => $this->dateValue(
                $file->updated_at
            ),
        ];
    }
}
