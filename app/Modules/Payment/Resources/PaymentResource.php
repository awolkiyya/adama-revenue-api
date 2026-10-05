<?php

namespace App\Modules\Payment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the payment into an API representation.
     *
     * Payment is the common financial transaction.
     *
     * Method-specific information is exposed through:
     *
     * - cash_details
     * - bank_transfer_details
     * - online_details
     *
     * Method-specific relationships are only returned when
     * explicitly eager-loaded.
     *
     * Bank transfer evidence is attached to the
     * BankTransferDetail model and is therefore exposed
     * through bank_transfer_details.files.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' =>
                $this->id,

            'payment_number' =>
                $this->payment_number,

            'transaction_reference' =>
                $this->transaction_reference,


            /*
            |--------------------------------------------------------------------------
            | Business References
            |--------------------------------------------------------------------------
            */

            'invoice_id' =>
                $this->invoice_id,

            'citizen_id' =>
                $this->citizen_id,


            /*
            |--------------------------------------------------------------------------
            | Payment Classification
            |--------------------------------------------------------------------------
            */

            'payment_method' =>
                $this->enumValue(
                    $this->payment_method
                ),

            'payment_source' =>
                $this->payment_source,

            'status' =>
                $this->enumValue(
                    $this->status
                ),


            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            'amount' =>
                $this->amount !== null
                    ? (float) $this->amount
                    : null,

            'currency' =>
                $this->currency,


            /*
            |--------------------------------------------------------------------------
            | Payer Snapshot
            |--------------------------------------------------------------------------
            */

            'payer' => [
                'name' =>
                    $this->payer_name,

                'email' =>
                    $this->payer_email,

                'phone' =>
                    $this->payer_phone,
            ],


            /*
            |--------------------------------------------------------------------------
            | Processing / Verification
            |--------------------------------------------------------------------------
            */

            'processed_by' =>
                $this->processed_by,

            'processed_by_user' =>
                $this->whenLoaded(
                    'processedBy',
                    function () {
                        $user = $this->processedBy;

                        return $user
                            ? [
                                'id' =>
                                    $user->id,

                                'name' =>
                                    $user->name ?? null,
                            ]
                            : null;
                    }
                ),

            'verified_by' =>
                $this->verified_by,

            'verified_by_user' =>
                $this->whenLoaded(
                    'verifiedBy',
                    function () {
                        $user = $this->verifiedBy;

                        return $user
                            ? [
                                'id' =>
                                    $user->id,

                                'name' =>
                                    $user->name ?? null,
                            ]
                            : null;
                    }
                ),

            'verified_at' =>
                $this->verified_at?->toISOString(),


            /*
            |--------------------------------------------------------------------------
            | Failure Information
            |--------------------------------------------------------------------------
            */

            'failure_reason' =>
                $this->failure_reason,


            /*
            |--------------------------------------------------------------------------
            | Application Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' =>
                $this->metadata,


            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice' =>
                $this->whenLoaded(
                    'invoice',
                    function () {
                        $invoice = $this->invoice;

                        if (!$invoice) {
                            return null;
                        }

                        return [
                            'id' =>
                                $invoice->id,

                            'invoice_number' =>
                                $invoice->invoice_number ?? null,

                            'status' =>
                                $this->enumValue(
                                    $invoice->status
                                ),

                            'total_amount' =>
                                $invoice->total_amount !== null
                                    ? (float) $invoice->total_amount
                                    : null,

                            'paid_amount' =>
                                $invoice->paid_amount !== null
                                    ? (float) $invoice->paid_amount
                                    : null,

                            'balance_due' =>
                                $invoice->balance_due !== null
                                    ? (float) $invoice->balance_due
                                    : null,
                        ];
                    }
                ),


            /*
            |--------------------------------------------------------------------------
            | Citizen
            |--------------------------------------------------------------------------
            */

            'citizen' =>
                $this->whenLoaded(
                    'citizen',
                    function () {
                        $citizen = $this->citizen;

                        if (!$citizen) {
                            return null;
                        }

                        return [
                            'id' =>
                                $citizen->id,

                            'name' =>
                                $citizen->name ?? null,
                        ];
                    }
                ),


            /*
            |--------------------------------------------------------------------------
            | Cash Payment Details
            |--------------------------------------------------------------------------
            */

            'cash_details' =>
                $this->whenLoaded(
                    'cashDetails',
                    function () {
                        $cash = $this->cashDetails;

                        if (!$cash) {
                            return null;
                        }

                        return [
                            'id' =>
                                $cash->id,

                            'cash_receipt_number' =>
                                $cash->cash_receipt_number,

                            'cash_received_at' =>
                                $cash->cash_received_at
                                    ?->toISOString(),

                            'cashier_session_id' =>
                                $cash->cashier_session_id,

                            'received_by' =>
                                $cash->received_by,

                            'received_by_user' =>
                                $cash->relationLoaded('receivedBy')
                                    ? (
                                        $cash->receivedBy
                                            ? [
                                                'id' =>
                                                    $cash->receivedBy->id,

                                                'name' =>
                                                    $cash->receivedBy->name
                                                    ?? null,
                                            ]
                                            : null
                                    )
                                    : null,

                            'notes' =>
                                $cash->notes,
                        ];
                    }
                ),


            /*
            |--------------------------------------------------------------------------
            | Bank Transfer Details
            |--------------------------------------------------------------------------
            */

            'bank_transfer_details' =>
                $this->whenLoaded(
                    'bankTransferDetails',
                    function () {
                        $bank = $this->bankTransferDetails;

                        if (!$bank) {
                            return null;
                        }

                        return [
                            'id' =>
                                $bank->id,

                            'bank_account_id' =>
                                $bank->bank_account_id,

                            'transfer_reference' =>
                                $bank->transfer_reference,

                            'transfer_date' =>
                                $bank->transfer_date
                                    ?->toISOString(),

                            'sender_name' =>
                                $bank->sender_name,

                            'sender_account' =>
                                $bank->sender_account,

                            'verification_status' =>
                                $bank->verification_status,

                            'verified_by' =>
                                $bank->verified_by,

                            'verified_at' =>
                                $bank->verified_at
                                    ?->toISOString(),

                            'notes' =>
                                $bank->notes,


                            /*
                            |--------------------------------------------------------------------------
                            | Bank Account
                            |--------------------------------------------------------------------------
                            */

                            'bank_account' =>
                                $bank->relationLoaded('bankAccount')
                                    ? (
                                        $bank->bankAccount
                                            ? [
                                                'id' =>
                                                    $bank->bankAccount->id,

                                                'bank_name' =>
                                                    $bank->bankAccount->bank_name,

                                                'account_name' =>
                                                    $bank->bankAccount->account_name,

                                                'account_number' =>
                                                    $bank->bankAccount->account_number,

                                                'currency' =>
                                                    $bank->bankAccount->currency,
                                            ]
                                            : null
                                    )
                                    : null,


                            /*
                            |--------------------------------------------------------------------------
                            | Bank Transfer Evidence
                            |--------------------------------------------------------------------------
                            |
                            | Evidence is attached to BankTransferDetail
                            | through the polymorphic files relationship.
                            |
                            | The relationship must be eager-loaded as:
                            |
                            | bankTransferDetails.files
                            |
                            */

                            'files' =>
                                $bank->relationLoaded('files')
                                    ? $bank->files->map(
                                        function ($file) {
                                            return [
                                                'id' =>
                                                    $file->id,

                                                'original_name' =>
                                                    $file->original_name,

                                                'mime_type' =>
                                                    $file->mime_type,

                                                'size' =>
                                                    $file->size,

                                                'storage_path' =>
                                                    $file->storage_path,

                                                'category' =>
                                                    $file->category,

                                                'visibility' =>
                                                    $file->visibility,

                                                'created_at' =>
                                                    $file->created_at
                                                        ?->toISOString(),

                                                'updated_at' =>
                                                    $file->updated_at
                                                        ?->toISOString(),
                                            ];
                                        }
                                    )->values()
                                    : null,
                        ];
                    }
                ),


            /*
            |--------------------------------------------------------------------------
            | Online Payment Details
            |--------------------------------------------------------------------------
            */

            'online_details' =>
                $this->whenLoaded(
                    'onlineDetails',
                    function () {
                        $online = $this->onlineDetails;

                        if (!$online) {
                            return null;
                        }

                        return [
                            'id' =>
                                $online->id,

                            'payment_provider_id' =>
                                $online->payment_provider_id,

                            'checkout_reference' =>
                                $online->checkout_reference,

                            'provider_transaction_id' =>
                                $online->provider_transaction_id,

                            'checkout_url' =>
                                $online->checkout_url,

                            'provider_status' =>
                                $online->provider_status,

                            'callback_received_at' =>
                                $online->callback_received_at
                                    ?->toISOString(),

                            'paid_at' =>
                                $online->paid_at
                                    ?->toISOString(),

                            'payment_provider' =>
                                $online->relationLoaded(
                                    'paymentProvider'
                                )
                                    ? (
                                        $online->paymentProvider
                                            ? [
                                                'id' =>
                                                    $online
                                                        ->paymentProvider
                                                        ->id,

                                                'code' =>
                                                    $online
                                                        ->paymentProvider
                                                        ->code,

                                                'name' =>
                                                    $online
                                                        ->paymentProvider
                                                        ->name,
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
            | These are files directly attached to Payment.
            |
            | Bank transfer evidence is intentionally NOT duplicated here.
            | It belongs under bank_transfer_details.files.
            |
            */

            'files' =>
                $this->whenLoaded(
                    'files',
                    fn () => $this->files->map(
                        function ($file) {
                            return [
                                'id' =>
                                    $file->id,

                                'original_name' =>
                                    $file->original_name,

                                'mime_type' =>
                                    $file->mime_type,

                                'size' =>
                                    $file->size,

                                'storage_path' =>
                                    $file->storage_path,

                                'category' =>
                                    $file->category,

                                'visibility' =>
                                    $file->visibility,

                                'created_at' =>
                                    $file->created_at
                                        ?->toISOString(),

                                'updated_at' =>
                                    $file->updated_at
                                        ?->toISOString(),
                            ];
                        }
                    )->values()
                ),


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Safely return the backing value of a PHP enum.
     *
     * Supports:
     *
     * - BackedEnum instances
     * - scalar values
     */
    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
