<?php

namespace App\Modules\Revenue\Resources;

use App\Models\InterestRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InterestRule
 */
class InterestRuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Interest Rate
            |--------------------------------------------------------------------------
            |
            | Rate is stored as a percentage.
            |
            | Example:
            | 24.7250 = 24.725%
            |
            */

            'rate' => $this->rate !== null
                ? (float) $this->rate
                : null,

            'rate_formatted' => $this->rate !== null
                ? rtrim(
                    rtrim(
                        number_format(
                            (float) $this->rate,
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
            | Rate Period
            |--------------------------------------------------------------------------
            */

            'rate_period' => $this->rate_period,

            'rate_period_label' => match ($this->rate_period) {
                InterestRule::RATE_PERIOD_YEAR => 'Annual',
                InterestRule::RATE_PERIOD_MONTH => 'Monthly',
                InterestRule::RATE_PERIOD_DAY => 'Daily',
                default => (string) $this->rate_period,
            },

            /*
            |--------------------------------------------------------------------------
            | Calculation Method
            |--------------------------------------------------------------------------
            */

            'calculation_method' => $this->calculation_method,

            'calculation_method_label' => match ($this->calculation_method) {
                InterestRule::METHOD_SIMPLE => 'Simple Interest',
                InterestRule::METHOD_COMPOUND => 'Compound Interest',
                default => (string) $this->calculation_method,
            },

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            'calculation_basis' => $this->calculation_basis,

            'calculation_basis_label' => match ($this->calculation_basis) {
                InterestRule::BASIS_PRINCIPAL => 'Principal',
                InterestRule::BASIS_OUTSTANDING => 'Outstanding Amount',
                default => (string) $this->calculation_basis,
            },

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effective_from' => $this->effective_from?->format('Y-m-d'),

            'effective_to' => $this->effective_to?->format('Y-m-d'),

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => (bool) $this->is_active,

            'status' => $this->is_active
                ? 'ACTIVE'
                : 'INACTIVE',

            'status_label' => $this->is_active
                ? 'Active'
                : 'Inactive',

            /*
            |--------------------------------------------------------------------------
            | Legal Information
            |--------------------------------------------------------------------------
            */

            'legal_reference' => $this->legal_reference,

            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'created_by' => $this->created_by,

            'updated_by' => $this->updated_by,

            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
            |
            | These are only included when the controller/service has
            | explicitly eager-loaded the relationships.
            |
            */

            'created_by_user' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                    'email' => $this->createdBy->email,
                ];
            }),

            'updated_by_user' => $this->whenLoaded('updatedBy', function () {
                return [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->name,
                    'email' => $this->updatedBy->email,
                ];
            }),

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