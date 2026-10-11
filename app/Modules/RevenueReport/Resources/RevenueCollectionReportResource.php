<?php

namespace App\Modules\RevenueReport\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueCollectionTransactionResource extends JsonResource
{
    /**
     * Transform a payment into a report transaction.
     */
    public function toArray(Request $request): array
    {
        $paymentMethod = $this->payment_method;
        $paymentProvider = $this->payment_provider;
        $paymentStatus = $this->status;

        return [
            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Payment Identification
            |--------------------------------------------------------------------------
            */

            'payment_number' => $this->payment_number,
            'transaction_reference' => $this->transaction_reference,

            /*
            |--------------------------------------------------------------------------
            | Invoice Information
            |--------------------------------------------------------------------------
            */

            'invoice' => [
                'id' => $this->invoice_id,
                'number' => $this->whenLoaded(
                    'invoice',
                    fn () => $this->invoice?->invoice_number
                ),
                'source_type' => $this->whenLoaded(
                    'invoice',
                    fn () => $this->invoice?->source_type
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Payer Snapshot
            |--------------------------------------------------------------------------
            */

            'payer' => [
                'name' => $this->payer_name,
                'email' => $this->payer_email,
                'phone' => $this->payer_phone,
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Classification
            |--------------------------------------------------------------------------
            */

            'payment_method' => $this->enumValue($paymentMethod),

            'payment_provider' => $this->enumValue(
                $paymentProvider
            ),

            'payment_source' => $this->payment_source,

            'status' => $this->enumValue($paymentStatus),

            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            'amount' => $this->amount !== null
                ? number_format((float) $this->amount, 2, '.', '')
                : '0.00',

            'currency' => $this->currency,

            /*
            |--------------------------------------------------------------------------
            | Processing Information
            |--------------------------------------------------------------------------
            */

            'processed_by' => $this->processed_by,

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),

            'report_date' => $this->created_at?->toDateString(),
        ];
    }

    /**
     * Support both backed enums and plain database strings.
     */
    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}