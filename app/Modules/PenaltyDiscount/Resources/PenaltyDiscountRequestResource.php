<?php

namespace App\Http\Resources;

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
            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id' => $this->invoice_id,

            'invoice' => $this->whenLoaded(
                'invoice',
                fn () => [
                    'id' => $this->invoice->id,
                    'invoice_number' =>
                        $this->invoice->invoice_number,
                    'status' =>
                        $this->invoice->status,
                    'subtotal' =>
                        $this->invoice->subtotal,
                    'penalty_amount' =>
                        $this->invoice->penalty_amount,
                    'penalty_discount_amount' =>
                        $this->invoice->penalty_discount_amount,
                    'interest_amount' =>
                        $this->invoice->interest_amount,
                    'total_amount' =>
                        $this->invoice->total_amount,
                    'paid_amount' =>
                        $this->invoice->paid_amount,
                    'balance_due' =>
                        $this->invoice->balance_due,
                    'due_date' =>
                        $this->invoice->due_date?->toDateString(),
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
                fn () => [
                    'id' => $this->citizen->id,
                    'name' =>
                        $this->citizen->name ?? null,
                ]
            ),

            /*
            |--------------------------------------------------------------------------
            | Request
            |--------------------------------------------------------------------------
            */

            'requested_amount' =>
                $this->requested_amount,

            'reason' =>
                $this->reason,

            'status' =>
                $this->status,

            'submitted_at' =>
                $this->submitted_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Decision
            |--------------------------------------------------------------------------
            */

            'decision' =>
                $this->decision,

            'approved_amount' =>
                $this->approved_amount,

            'decision_reason' =>
                $this->decision_reason,

            'decided_at' =>
                $this->decided_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Application
            |--------------------------------------------------------------------------
            */

            'applied_to_invoice' =>
                $this->applied_to_invoice,

            'applied_at' =>
                $this->applied_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Users
            |--------------------------------------------------------------------------
            */

            'created_by' =>
                $this->created_by,

            'creator' => $this->whenLoaded(
                'creator',
                fn () => [
                    'id' => $this->creator->id,
                    'name' =>
                        $this->creator->name,
                ]
            ),

            'decided_by' =>
                $this->decided_by,

            'decider' => $this->whenLoaded(
                'decider',
                fn () => [
                    'id' => $this->decider->id,
                    'name' =>
                        $this->decider->name,
                ]
            ),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}
