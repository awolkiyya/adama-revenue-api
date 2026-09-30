<?php

namespace App\Modules\Taxpayer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxpayerInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'invoice_number' => $this->invoice_number,

            'source_type' => $this->source_type,

            'status' => $this->status,

            'currency' => $this->currency,

            'subtotal' => $this->decimal($this->subtotal),

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

            'paid_amount' => $this->decimal(
                $this->paid_amount
            ),

            'balance_due' => $this->decimal(
                $this->balance_due
            ),

            'issued_at' => $this->issued_at?->toISOString(),

            'due_date' => $this->due_date?->toDateString(),

            'paid_at' => $this->paid_at?->toISOString(),

            'is_overdue' => $this->status === 'OVERDUE',

            'is_fully_paid' => $this->status === 'PAID',

            'items' => TaxpayerInvoiceItemResource::collection(
                $this->whenLoaded('items')
            ),
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