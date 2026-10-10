<?php

namespace App\Modules\PenaltyDiscount\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenaltyDiscountRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Primary Information
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id' => $this->invoice_id,

            'invoice' => $this->whenLoaded(
                'invoice',
                fn () => $this->invoice === null
                    ? null
                    : [
                        'id' => $this->invoice->id,
                        'invoice_number' => $this->invoice->invoice_number,
                        'status' => $this->invoice->status,
                        'subtotal' => $this->invoice->subtotal,
                        'penalty_amount' => $this->invoice->penalty_amount,
                        'penalty_discount_amount' => $this->invoice->penalty_discount_amount,
                        'interest_amount' => $this->invoice->interest_amount,
                        'total_amount' => $this->invoice->total_amount,
                        'paid_amount' => $this->invoice->paid_amount,
                        'balance_due' => $this->invoice->balance_due,
                        'due_date' => $this->invoice->due_date?->toDateString(),
                    ]
            ),

            /*
            |--------------------------------------------------------------------------
            | Citizen
            |--------------------------------------------------------------------------
            */

            'citizen_id' => $this->citizen_id,

            'citizen' => $this->whenLoaded(
                'citizen',
                fn () => $this->citizen === null
                    ? null
                    : [
                        'id' => $this->citizen->id,
                        'name' => $this->citizen->name ?? null,
                    ]
            ),

            /*
            |--------------------------------------------------------------------------
            | Request Details
            |--------------------------------------------------------------------------
            */

            'requested_amount' => $this->requested_amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Supporting Documents
            |--------------------------------------------------------------------------
            |
            | Return document metadata only.
            | Documents remain private and must be opened through the
            | application's authenticated private-file endpoint.
            |
            */

            'supporting_files' => $this->whenLoaded(
                'supportingFiles',
                fn () => $this->supportingFiles
                    ->map(fn ($file) => [
                        'id' => $file->id,
                        'uuid' => $file->uuid,
                        'original_name' => $file->original_name,
                        'mime_type' => $file->mime_type,
                        'extension' => $file->extension,
                        'size' => $file->size
                            ?? $file->size_bytes
                            ?? null,
                        'status' => $file->status,
                        'created_at' => $file->created_at?->toISOString(),
                    ])
                    ->values()
                    ->all()
            ),

            /*
            |--------------------------------------------------------------------------
            | Decision Details
            |--------------------------------------------------------------------------
            */

            'decision' => $this->decision,
            'approved_amount' => $this->approved_amount,
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Invoice Application
            |--------------------------------------------------------------------------
            */

            'applied_to_invoice' => (bool) $this->applied_to_invoice,
            'applied_at' => $this->applied_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Creator
            |--------------------------------------------------------------------------
            */

            'created_by' => $this->created_by,

            'creator' => $this->whenLoaded(
                'creator',
                fn () => $this->creator === null
                    ? null
                    : [
                        'id' => $this->creator->id,
                        'name' => $this->creator->name ?? null,
                    ]
            ),

            /*
            |--------------------------------------------------------------------------
            | Decision Maker
            |--------------------------------------------------------------------------
            */

            'decided_by' => $this->decided_by,

            'decider' => $this->whenLoaded(
                'decider',
                fn () => $this->decider === null
                    ? null
                    : [
                        'id' => $this->decider->id,
                        'name' => $this->decider->name ?? null,
                    ]
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
}
