<?php

namespace App\Modules\Agent\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentPendingInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'invoice_number' => $this->invoice_number,

            'status' => $this->status,

            'issued_at' => $this->issued_at,
            'due_date' => $this->due_date,

            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'balance_amount' => $this->balance_due,

            'taxpayer' => $this->citizen
                ? [
                    'id' => $this->citizen->id,
                    'taxpayer_number' => $this->citizen->citizen_uid,
                    'name' => $this->citizen->full_name,
                    'phone' => $this->citizen->phone,
                ]
                : null,

            'services' => $this->items
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->service?->name
                            ?? $item->description,
                        'description' => $item->description,
                        'amount' => $item->total_amount,
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}