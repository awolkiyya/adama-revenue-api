<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PenaltyRule
 */
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
            | Penalty Rate Configuration
            |--------------------------------------------------------------------------
            |
            | Rates are returned as numeric values for API consumers.
            |
            | The backend model keeps the underlying database values
            | as decimal strings to preserve financial precision.
            |
            */

            'initial_rate' => $this->initial_rate !== null
                ? (float) $this->initial_rate
                : null,

            'increment_rate' => $this->increment_rate !== null
                ? (float) $this->increment_rate
                : null,

            'maximum_rate' => $this->maximum_rate !== null
                ? (float) $this->maximum_rate
                : null,

            /*
            |--------------------------------------------------------------------------
            | Formatted Rate Values
            |--------------------------------------------------------------------------
            |
            | Convenient display values for frontend clients.
            |
            */

            'initial_rate_formatted' =>
                $this->initial_rate !== null
                    ? rtrim(
                        rtrim(
                            number_format(
                                (float) $this->initial_rate,
                                4,
                                '.',
                                ''
                            ),
                            '0'
                        ),
                        '.'
                    ) . '%'
                    : null,

            'increment_rate_formatted' =>
                $this->increment_rate !== null
                    ? rtrim(
                        rtrim(
                            number_format(
                                (float) $this->increment_rate,
                                4,
                                '.',
                                ''
                            ),
                            '0'
                        ),
                        '.'
                    ) . '%'
                    : null,

            'maximum_rate_formatted' =>
                $this->maximum_rate !== null
                    ? rtrim(
                        rtrim(
                            number_format(
                                (float) $this->maximum_rate,
                                4,
                                '.',
                                ''
                            ),
                            '0'
                        ),
                        '.'
                    ) . '%'
                    : null,

            /*
            |--------------------------------------------------------------------------
            | Progression
            |--------------------------------------------------------------------------
            */

            'progression_label' =>
                $this->progression_label,

            'rate_summary' =>
                $this->rate_summary,

            /*
            |--------------------------------------------------------------------------
            | Commencement Configuration
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | There is intentionally NO:
            |
            |     start_fiscal_month
            |
            | The fixed fiscal commencement date is resolved from:
            |
            |     revenue_settings.payment_start_month
            |     revenue_settings.payment_start_day
            |
            | start_type only determines WHICH commencement strategy
            | the penalty engine uses.
            |
            */

            'start_type' => $this->start_type,

            'start_type_label' =>
                $this->start_type_label,

            /*
            |--------------------------------------------------------------------------
            | Increment Configuration
            |--------------------------------------------------------------------------
            */

            'increment_period' =>
                $this->increment_period,

            'increment_period_label' =>
                $this->increment_period_label,

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            'calculation_basis' =>
                $this->calculation_basis,

            'calculation_basis_label' =>
                $this->calculation_basis_label,

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

            'is_active' =>
                (bool) $this->is_active,

            'status' =>
                $this->is_active
                    ? 'ACTIVE'
                    : 'INACTIVE',

            'status_label' =>
                $this->status_label,

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

            'description' =>
                $this->description,

            'legal_reference' =>
                $this->legal_reference,

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'created_by' =>
                $this->created_by,

            'updated_by' =>
                $this->updated_by,

            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
            |
            | Only included when the corresponding relationship has
            | explicitly been loaded by the controller/service.
            |
            */

            'created_by_user' =>
                $this->whenLoaded(
                    'createdBy',
                    fn () => $this->createdBy
                        ? [
                            'id' =>
                                $this->createdBy->id,

                            'name' =>
                                $this->createdBy->name,

                            'email' =>
                                $this->createdBy->email,
                        ]
                        : null
                ),

            'updated_by_user' =>
                $this->whenLoaded(
                    'updatedBy',
                    fn () => $this->updatedBy
                        ? [
                            'id' =>
                                $this->updatedBy->id,

                            'name' =>
                                $this->updatedBy->name,

                            'email' =>
                                $this->updatedBy->email,
                        ]
                        : null
                ),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}