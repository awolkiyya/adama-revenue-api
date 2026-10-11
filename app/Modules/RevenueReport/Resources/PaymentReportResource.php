<?php

namespace App\Modules\RevenueReport\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReportResource extends JsonResource
{
    /**
     * Mask an account number, preserving its last four characters.
     */
    private function maskAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null || $accountNumber === '') {
            return null;
        }

        $length = strlen($accountNumber);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4)
            . substr($accountNumber, -4);
    }

    /**
     * Transform a payment into a report-friendly API response.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Payment Identification
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'payment_reference' => $this->payment_number,

            'receipt_number' => $this->receipt_number ?? null,

            /*
            |--------------------------------------------------------------------------
            | Invoice Information
            |--------------------------------------------------------------------------
            */

            'invoice_id' => $this->invoice_id,

            'invoice_reference' => $this->whenLoaded(
                'invoice',
                fn () => $this->invoice?->invoice_number
            ),

            /*
            |--------------------------------------------------------------------------
            | Taxpayer Information
            |--------------------------------------------------------------------------
            */

            'taxpayer' => $this->whenLoaded('invoice', function () {
                $invoice = $this->invoice;
            
                if (! $invoice || ! $invoice->relationLoaded('citizen')) {
                    return null;
                }
            
                $citizen = $invoice->citizen;
            
                if (! $citizen) {
                    return null;
                }
            
                return [
                    'id' => $citizen->id,
                    'citizen_uid' => $citizen->citizen_uid,
                    'name' => $citizen->full_name,
                    'national_id' => $citizen->national_id,
                    'phone' => $citizen->phone,
                    'email' => $citizen->email,
                ];
            }),
            
            

            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            'amount' => $this->amount !== null
                ? (string) $this->amount
                : '0.00',

            'currency' => $this->currency ?? 'ETB',

            /*
            |--------------------------------------------------------------------------
            | Payment Classification
            |--------------------------------------------------------------------------
            */

            'status' => $this->status?->value ?? $this->status,

            'payment_method' => $this->payment_method?->value
                ?? $this->payment_method,

            'payment_provider' => $this->payment_provider?->value
                ?? $this->payment_provider,

            'payment_source' => $this->payment_source,

            /*
            |--------------------------------------------------------------------------
            | Bank Transfer Details
            |--------------------------------------------------------------------------
            |
            | Bank information is returned from the actual
            | bankTransferDetails.bankAccount relationship.
            */

            'bank_transfer_details' => $this->whenLoaded(
                'bankTransferDetails',
                function (): ?array {
                    $details = $this->bankTransferDetails;

                    if (! $details) {
                        return null;
                    }

                    $bankAccount = $details->relationLoaded('bankAccount')
                        ? $details->bankAccount
                        : null;

                    return [
                        'transfer_reference' =>
                            $details->transfer_reference,

                        'transfer_date' =>
                            $details->transfer_date?->toISOString(),

                        'sender_name' =>
                            $details->sender_name,

                        'verification_status' =>
                            $details->verification_status,

                        'bank_account' => $bankAccount
                            ? [
                                'id' => $bankAccount->id,
                                'bank_name' => $bankAccount->bank_name,
                                'account_name' => $bankAccount->account_name,
                                'account_number' => $this->maskAccountNumber(
                                    $bankAccount->account_number
                                ),
                                'currency' => $bankAccount->currency,
                            ]
                            : null,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Transaction References
            |--------------------------------------------------------------------------
            */

            'transaction_reference' => $this->transaction_reference,

            /*
            |--------------------------------------------------------------------------
            | Payment Processing Officer
            |--------------------------------------------------------------------------
            */

            'processed_by' => $this->whenLoaded(
                'processedBy',
                function (): ?array {
                    $user = $this->processedBy;

                    if (! $user) {
                        return null;
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Payment Verification Officer
            |--------------------------------------------------------------------------
            */

            'verified_by' => $this->whenLoaded(
                'verifiedBy',
                function (): ?array {
                    $user = $this->verifiedBy;

                    if (! $user) {
                        return null;
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Audit Timestamps
            |--------------------------------------------------------------------------
            */

            'verified_at' => $this->verified_at?->toISOString(),

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
