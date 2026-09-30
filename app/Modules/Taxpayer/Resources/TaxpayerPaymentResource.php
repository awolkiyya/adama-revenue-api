<?php

namespace App\Modules\Taxpayer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxpayerPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'payment_number' => $this->payment_number,

            'invoice_id' => $this->invoice_id,

            'invoice_number' => $this->invoice?->invoice_number,

            'payment_method' => $this->payment_method,

            'payment_provider' => $this->payment_provider,

            'status' => $this->status,

            'transaction_reference' => $this->transaction_reference,

            'provider_reference' => $this->provider_reference,

            'amount' => number_format(
                (float) $this->amount,
                2,
                '.',
                ''
            ),

            'currency' => $this->currency,

            'payment_date' => $this->payment_date?->toISOString(),

            'verified_at' => $this->verified_at?->toISOString(),

            'failure_reason' => $this->when(
                $this->status !== 'SUCCESS',
                $this->failure_reason
            ),
        ];
    }
}