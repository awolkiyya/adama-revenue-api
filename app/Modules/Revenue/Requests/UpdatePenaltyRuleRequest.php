<?php

namespace App\Modules\Revenue\Requests;

use App\Models\PenaltyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePenaltyRuleRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user can update
     * the requested penalty rule.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for updating a penalty rule.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'legal_reference' => [
                'nullable',
                'string',
                'max:500',
            ],

            /*
            |--------------------------------------------------------------------------
            | Progressive Penalty Rates
            |--------------------------------------------------------------------------
            |
            | Rates are stored as percentage points.
            |
            | Example:
            |
            |     initial_rate   = 5
            |     increment_rate = 2
            |     maximum_rate   = 25
            |
            | Result:
            |
            |     Month 1  = 5%
            |     Month 2  = 7%
            |     Month 3  = 9%
            |     ...
            |     Month 11 = 25%
            |     Month 12+ = 25%
            |
            */

            'initial_rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,4',
            ],

            'increment_rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,4',
            ],

            'maximum_rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,4',
            ],

            /*
            |--------------------------------------------------------------------------
            | Penalty Commencement Type
            |--------------------------------------------------------------------------
            |
            | FIXED_FISCAL_MONTH:
            |
            |     The actual fiscal payment-period start is resolved
            |     from Revenue General Settings:
            |
            |         payment_start_month
            |         payment_start_day
            |
            | AGREEMENT_DATE:
            |
            |     The commencement date is resolved from the
            |     applicable agreement date.
            |
            | IMPORTANT:
            |
            |     No fiscal month is stored on the penalty rule.
            |
            */

            'start_type' => [
                'required',
                Rule::in([
                    PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH,
                    PenaltyRule::START_TYPE_AGREEMENT_DATE,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Increment Period
            |--------------------------------------------------------------------------
            |
            | MONTH is currently the only supported progression period.
            |
            */

            'increment_period' => [
                'sometimes',
                Rule::in([
                    PenaltyRule::INCREMENT_PERIOD_MONTH,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            'calculation_basis' => [
                'required',
                Rule::in([
                    PenaltyRule::CALCULATION_BASIS_PRINCIPAL,
                    PenaltyRule::CALCULATION_BASIS_OUTSTANDING,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effective_from' => [
                'required',
                'date',
            ],

            'effective_to' => [
                'nullable',
                'date',
                'after_or_equal:effective_from',
            ],

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */

            'name.required' =>
                'The penalty rule name is required.',

            'name.max' =>
                'The penalty rule name may not exceed 150 characters.',

            'description.max' =>
                'The description may not exceed 2000 characters.',

            'legal_reference.max' =>
                'The legal reference may not exceed 500 characters.',

            /*
            |--------------------------------------------------------------------------
            | Rates
            |--------------------------------------------------------------------------
            */

            'initial_rate.required' =>
                'The initial penalty rate is required.',

            'initial_rate.numeric' =>
                'The initial penalty rate must be a number.',

            'initial_rate.min' =>
                'The initial penalty rate cannot be negative.',

            'initial_rate.max' =>
                'The initial penalty rate cannot exceed 100%.',

            'initial_rate.decimal' =>
                'The initial penalty rate may have up to 4 decimal places.',

            'increment_rate.required' =>
                'The increment penalty rate is required.',

            'increment_rate.numeric' =>
                'The increment penalty rate must be a number.',

            'increment_rate.min' =>
                'The increment penalty rate cannot be negative.',

            'increment_rate.max' =>
                'The increment penalty rate cannot exceed 100%.',

            'increment_rate.decimal' =>
                'The increment penalty rate may have up to 4 decimal places.',

            'maximum_rate.required' =>
                'The maximum penalty rate is required.',

            'maximum_rate.numeric' =>
                'The maximum penalty rate must be a number.',

            'maximum_rate.min' =>
                'The maximum penalty rate cannot be negative.',

            'maximum_rate.max' =>
                'The maximum penalty rate cannot exceed 100%.',

            'maximum_rate.decimal' =>
                'The maximum penalty rate may have up to 4 decimal places.',

            /*
            |--------------------------------------------------------------------------
            | Start Configuration
            |--------------------------------------------------------------------------
            */

            'start_type.required' =>
                'The penalty commencement type is required.',

            'start_type.in' =>
                'The selected penalty commencement type is invalid.',

            /*
            |--------------------------------------------------------------------------
            | Increment Period
            |--------------------------------------------------------------------------
            */

            'increment_period.in' =>
                'The penalty increment period must be MONTH.',

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            'calculation_basis.required' =>
                'The penalty calculation basis is required.',

            'calculation_basis.in' =>
                'The selected penalty calculation basis is invalid.',

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effective_from.required' =>
                'The effective start date is required.',

            'effective_from.date' =>
                'The effective start date must be a valid date.',

            'effective_to.date' =>
                'The effective end date must be a valid date.',

            'effective_to.after_or_equal' =>
                'The effective end date must be on or after the effective start date.',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active.boolean' =>
                'The active status must be true or false.',
        ];
    }

    /**
     * Normalize request data before validation.
     */
    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Increment Period
        |--------------------------------------------------------------------------
        |
        | MONTH is currently the only supported increment period.
        |
        | The client does not need to control this value.
        |
        */

        $this->merge([
            'increment_period' =>
                PenaltyRule::INCREMENT_PERIOD_MONTH,
        ]);
    }

    /**
     * Additional business validation.
     */
    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /*
            |--------------------------------------------------------------------------
            | Maximum Rate
            |--------------------------------------------------------------------------
            |
            | maximum_rate must be greater than or equal to initial_rate.
            |
            | BCMath is used instead of floating-point comparison because
            | these values represent financial/legal percentage rates.
            |
            */

            $initialRate = $this->input('initial_rate');
            $maximumRate = $this->input('maximum_rate');

            if (
                $initialRate !== null &&
                $maximumRate !== null &&
                is_numeric($initialRate) &&
                is_numeric($maximumRate) &&
                bccomp(
                    (string) $maximumRate,
                    (string) $initialRate,
                    4
                ) < 0
            ) {
                $validator->errors()->add(
                    'maximum_rate',
                    'The maximum penalty rate must be greater than or equal to the initial penalty rate.'
                );
            }
        });
    }
}