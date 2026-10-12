<?php

namespace App\Modules\CashReconciliation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\CashReconciliation;

class CashReconciliationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        /** @var CashReconciliation $reconciliation */
        $reconciliation = $this->resource;

        return [
            'id' => $reconciliation->getKey(),

            /*
            |--------------------------------------------------------------------------
            | Collector
            |--------------------------------------------------------------------------
            */

            'collector_id' => $reconciliation->collector_id,

            'collector' => $this->whenLoaded(
                'collector',
                function () use ($reconciliation): ?array {
                    $collector = $reconciliation->collector;

                    if (! $collector) {
                        return null;
                    }

                    return [
                        'id' => $collector->getKey(),
                        'name' => $collector->name
                            ?? $collector->full_name
                            ?? $collector->email,
                        'email' => $collector->email,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Reconciliation Period
            |--------------------------------------------------------------------------
            */

            'period_start' => $reconciliation->period_start?->toISOString(),

            'period_end' => $reconciliation->period_end?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Financial Summary
            |--------------------------------------------------------------------------
            */

            'expected_amount' => $this->formatAmount(
                $reconciliation->expected_amount
            ),

            'actual_amount' => $this->formatNullableAmount(
                $reconciliation->actual_amount
            ),

            'difference_amount' => $this->formatNullableAmount(
                $reconciliation->difference_amount
            ),

            'difference_type' => $this->differenceType(
                $reconciliation
            ),

            'has_discrepancy' => $reconciliation->hasDiscrepancy(),

            'is_balanced' => $reconciliation->isBalanced(),

            'has_shortage' => $reconciliation->hasShortage(),

            'has_overage' => $reconciliation->hasOverage(),

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'collector_notes' => $reconciliation->collector_notes,

            'review_notes' => $reconciliation->review_notes,

            /*
            |--------------------------------------------------------------------------
            | Review
            |--------------------------------------------------------------------------
            */

            'reviewed_by' => $reconciliation->reviewed_by,

            'reviewer' => $this->whenLoaded(
                'reviewer',
                function () use ($reconciliation): ?array {
                    $reviewer = $reconciliation->reviewer;

                    if (! $reviewer) {
                        return null;
                    }

                    return [
                        'id' => $reviewer->getKey(),
                        'name' => $reviewer->name
                            ?? $reviewer->full_name
                            ?? $reviewer->email,
                        'email' => $reviewer->email,
                    ];
                }
            ),

            'reviewed_at' => $reconciliation->reviewed_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Workflow
            |--------------------------------------------------------------------------
            */

            'status' => $reconciliation->status,

            'is_pending_review' => $reconciliation->isPendingReview(),

            'is_reconciled' => $reconciliation->isReconciled(),

            'is_discrepancy' => $reconciliation->isDiscrepancy(),

            'is_returned' => $reconciliation->isReturned(),

            'is_editable' => $reconciliation->isEditable(),

            'is_submitted' => $reconciliation->isSubmitted(),

            'submitted_at' => $reconciliation->submitted_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $reconciliation->created_at?->toISOString(),

            'updated_at' => $reconciliation->updated_at?->toISOString(),
        ];
    }

    /**
     * Format a non-null monetary amount to two decimal places.
     */
    private function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    /**
     * Format a nullable monetary amount.
     */
    private function formatNullableAmount(mixed $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return $this->formatAmount($amount);
    }

    /**
     * Determine the financial difference classification.
     */
    private function differenceType(
        CashReconciliation $reconciliation
    ): string {
        if (
            $reconciliation->actual_amount === null
            || $reconciliation->difference_amount === null
        ) {
            return 'NOT_RECORDED';
        }

        if ($reconciliation->hasShortage()) {
            return 'SHORTAGE';
        }

        if ($reconciliation->hasOverage()) {
            return 'OVERAGE';
        }

        return 'BALANCED';
    }
}
