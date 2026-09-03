<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenaltyRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'name' => $this->name,

            /*
            |--------------------------------------------------------------------------
            | Scope
            |--------------------------------------------------------------------------
            */

            'scope' => $this->isGlobal()
                ? 'GLOBAL'
                : 'SERVICE_SPECIFIC',

            'scope_label' => $this->scope_label,

            'revenue_service_id' => $this->revenue_service_id,

            'revenue_service' => $this->when(
                $this->relationLoaded('revenueService')
                && $this->revenueService,
                fn () => [
                    'id' => $this->revenueService->id,
                    'name' => $this->revenueService->name,
                ]
            ),

            /*
            |--------------------------------------------------------------------------
            | Calculation
            |--------------------------------------------------------------------------
            */

            'calculation_type' => $this->calculation_type,

            'calculation_type_label' =>
                $this->calculation_type_label,

            'fixed_amount' => $this->fixed_amount,

            'initial_rate' => $this->initial_rate,

            'increment_rate' => $this->increment_rate,

            'maximum_rate' => $this->maximum_rate,

            /*
            |--------------------------------------------------------------------------
            | Start Configuration
            |--------------------------------------------------------------------------
            */

            'start_type' => $this->start_type,

            'start_offset_value' => $this->start_offset_value,

            'start_offset_unit' => $this->start_offset_unit,

            'increment_period' => $this->increment_period,

            'calculation_basis' => $this->calculation_basis,

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effective_from' =>
                $this->effective_from?->toDateString(),

            'effective_to' =>
                $this->effective_to?->toDateString(),

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => $this->is_active,

            'status' => $this->is_active
                ? 'ACTIVE'
                : 'INACTIVE',

            'is_effective_today' =>
                $this->is_active
                && $this->isEffectiveOn(
                    now()->toDateString()
                ),

            /*
            |--------------------------------------------------------------------------
            | Description / Legal Reference
            |--------------------------------------------------------------------------
            */

            'description' => $this->description,

            'legal_reference' => $this->legal_reference,

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'created_by' => $this->created_by,

            'updated_by' => $this->updated_by,

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}