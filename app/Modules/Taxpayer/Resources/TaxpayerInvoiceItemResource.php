<?php

namespace App\Modules\Taxpayer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxpayerInvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'line_number' => $this->line_number,

            'service_id' => $this->service_id,

            'service_name' => $this->service?->name,

            'description' => $this->description,

            'quantity' => $this->quantity !== null
                ? number_format(
                    (float) $this->quantity,
                    2,
                    '.',
                    ''
                )
                : null,

            'unit' => $this->unit,

            'unit_price' => $this->unit_price !== null
                ? number_format(
                    (float) $this->unit_price,
                    2,
                    '.',
                    ''
                )
                : null,

            'amount' => $this->decimal($this->amount),

            'discount_amount' => $this->decimal(
                $this->discount_amount
            ),

            'penalty_amount' => $this->decimal(
                $this->penalty_amount
            ),

            'interest_amount' => $this->decimal(
                $this->interest_amount
            ),

            'total_amount' => $this->decimal(
                $this->total_amount
            ),

            'currency' => $this->currency,
        ];
    }

    private function decimal($value): string
    {
        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
    }
}