<?php

namespace App\Modules\CashReconciliation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $difference = $this->difference_amount;

        $differenceType = null;

        if ($difference !== null) {
            $comparison = bccomp((string) $difference, '0.00', 2);

            $differenceType = match (true) {
                $comparison === 0 => 'BALANCED',
                $comparison < 0 => 'SHORTAGE',
                default => 'OVERAGE',
            };
        }

        return [
            'id' => $this->id,

            'collector_id' => $this->collector_id,

            'collector' => $this->whenLoaded(
                'collector',
                fn () => $this->collector ? [
                    'id' => $this->collector->id,
                    'name' => $this->collector->name,
                    'email' => $this->collector->email,
                ] : null
            ),

            'period_start' => $this->period_start?->toISOString(),
            'period_end' => $this->period_end?->toISOString(),

            'expected_amount' => $this->expected_amount === null
                ? null
                : (string) $this->expected_amount,

            'actual_amount' => $this->actual_amount === null
                ? null
                : (string) $this->actual_amount,

            'difference_amount' => $difference === null
                ? null
                : (string) $difference,

            'difference_type' => $differenceType,

            'collector_notes' => $this->collector_notes,
            'submitted_at' => $this->submitted_at?->toISOString(),

            'reviewed_by' => $this->reviewed_by,

            'reviewer' => $this->whenLoaded(
                'reviewer',
                fn () => $this->reviewer ? [
                    'id' => $this->reviewer->id,
                    'name' => $this->reviewer->name,
                    'email' => $this->reviewer->email,
                ] : null
            ),

            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'review_notes' => $this->review_notes,

            'status' => $this->status,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}