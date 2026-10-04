<?php

namespace App\Modules\Payment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
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

            'assessment_id' => $this->assessment_id,

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

            'status' => $this->enumValue(
                $this->status
            ),


            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            |
            | amount is cast by the model as decimal:2.
            |
            | We return it as a numeric value for the frontend.
            |
            */

            'amount' => $this->amount !== null
                ? (float) $this->amount
                : null,

            'currency' => $this->currency,


            /*
            |--------------------------------------------------------------------------
            | Payer Snapshot
            |--------------------------------------------------------------------------
            */

            'payer_name' => $this->payer_name,

            'payer_email' => $this->payer_email,

            'payer_phone' => $this->payer_phone,


            /*
            |--------------------------------------------------------------------------
            | Payment Dates
            |--------------------------------------------------------------------------
            */

            'payment_date' => $this->payment_date?->toISOString(),

            'verified_at' => $this->verified_at?->toISOString(),


            /*
            |--------------------------------------------------------------------------
            | Online Checkout
            |--------------------------------------------------------------------------
            */

            'checkout_url' => $this->checkout_url,


            /*
            |--------------------------------------------------------------------------
            | Failure Information
            |--------------------------------------------------------------------------
            */

            'failure_reason' => $this->failure_reason,


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'received_by' => $this->received_by,

            'verified_by' => $this->verified_by,


            /*
            |--------------------------------------------------------------------------
            | Provider / Application Data
            |--------------------------------------------------------------------------
            */

            'metadata' => $this->metadata,

            'provider_response' => $this->provider_response,


            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            |
            | These are only included when explicitly eager-loaded.
            |
            | This prevents the payment list endpoint from accidentally
            | generating N+1 queries.
            |
            */

            'invoice' => $this->whenLoaded(
                'invoice',
                fn () => $this->invoice
                    ? [
                        'id' => $this->invoice->id,
                    ]
                    : null
            ),

            'assessment' => $this->whenLoaded(
                'assessment',
                fn () => $this->assessment
                    ? [
                        'id' => $this->assessment->id,
                    ]
                    : null
            ),

            'citizen' => $this->whenLoaded(
                'citizen',
                fn () => $this->citizen
                    ? [
                        'id' => $this->citizen->id,
                    ]
                    : null
            ),

            'received_by_user' => $this->whenLoaded(
                'receivedBy',
                fn () => $this->receivedBy
                    ? [
                        'id' => $this->receivedBy->id,
                        'name' => $this->receivedBy->name
                            ?? null,
                    ]
                    : null
            ),

            'verified_by_user' => $this->whenLoaded(
                'verifiedBy',
                fn () => $this->verifiedBy
                    ? [
                        'id' => $this->verifiedBy->id,
                        'name' => $this->verifiedBy->name
                            ?? null,
                    ]
                    : null
            ),


            /*
            |--------------------------------------------------------------------------
            | Files
            |--------------------------------------------------------------------------
            */

            'payment_evidence' => $this->whenLoaded(
                'paymentEvidence',
                fn () => $this->paymentEvidence
            ),

            'receipts' => $this->whenLoaded(
                'receipts',
                fn () => $this->receipts
            ),

            'refund_evidence' => $this->whenLoaded(
                'refundEvidence',
                fn () => $this->refundEvidence
            ),


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }


    /**
     * Safely return the backing value of a PHP enum.
     *
     * Supports both:
     *
     * - BackedEnum
     * - plain scalar values
     */
    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}