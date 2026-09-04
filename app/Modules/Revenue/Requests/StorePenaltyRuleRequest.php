<?php

namespace App\Modules\Revenue\Requests;

use App\Models\PenaltyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePenaltyRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            |     Period 1 = 5%
            |     Period 2 = 7%
            |     Period 3 = 9%
            |     ...
            |     Maximum  = 25%
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
            | Fixed Fiscal Month
            |--------------------------------------------------------------------------
            |
            | Required only for FIXED_FISCAL_MONTH.
            |
            | Ethiopian fiscal months:
            |
            |     1 - 13
            |
            */

            'start_fiscal_month' => [
                'nullable',
                'integer',
                'min:1',
                'max:13',
                'required_if:start_type,FIXED_FISCAL_MONTH',
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

            'increment_rate.required' =>
                'The increment penalty rate is required.',

            'increment_rate.numeric' =>
                'The increment penalty rate must be a number.',

            'increment_rate.min' =>
                'The increment penalty rate cannot be negative.',

            'increment_rate.max' =>
                'The increment penalty rate cannot exceed 100%.',

            'maximum_rate.required' =>
                'The maximum penalty rate is required.',

            'maximum_rate.numeric' =>
                'The maximum penalty rate must be a number.',

            'maximum_rate.min' =>
                'The maximum penalty rate cannot be negative.',

            'maximum_rate.max' =>
                'The maximum penalty rate cannot exceed 100%.',

            /*
            |--------------------------------------------------------------------------
            | Start Configuration
            |--------------------------------------------------------------------------
            */

            'start_type.required' =>
                'The penalty commencement type is required.',

            'start_type.in' =>
                'The selected penalty commencement type is invalid.',

            'start_fiscal_month.required_if' =>
                'The fiscal month is required when the penalty start type is FIXED_FISCAL_MONTH.',

            'start_fiscal_month.integer' =>
                'The fiscal month must be a whole number.',

            'start_fiscal_month.min' =>
                'The fiscal month must be between 1 and 13.',

            'start_fiscal_month.max' =>
                'The fiscal month must be between 1 and 13.',

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

    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Agreement Date
        |--------------------------------------------------------------------------
        |
        | Fiscal month has no meaning when the penalty starts from the
        | agreement date.
        |
        */

        if (
            $this->input('start_type') ===
            PenaltyRule::START_TYPE_AGREEMENT_DATE
        ) {
            $this->merge([
                'start_fiscal_month' => null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Increment Period
        |--------------------------------------------------------------------------
        |
        | MONTH is currently the only supported increment period.
        |
        */

        $this->merge([
            'increment_period' =>
                PenaltyRule::INCREMENT_PERIOD_MONTH,
        ]);
    }

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
            */

            $initialRate = $this->input('initial_rate');
            $maximumRate = $this->input('maximum_rate');

            if (
                $initialRate !== null &&
                $maximumRate !== null &&
                is_numeric($initialRate) &&
                is_numeric($maximumRate) &&
                (float) $maximumRate < (float) $initialRate
            ) {
                $validator->errors()->add(
                    'maximum_rate',
                    'The maximum penalty rate must be greater than or equal to the initial penalty rate.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Fixed Fiscal Month
            |--------------------------------------------------------------------------
            */

            if (
                $this->input('start_type') ===
                    PenaltyRule::START_TYPE_FIXED_FISCAL_MONTH &&
                $this->input('start_fiscal_month') === null
            ) {
                $validator->errors()->add(
                    'start_fiscal_month',
                    'The fiscal month is required when the penalty start type is FIXED_FISCAL_MONTH.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Agreement Date
            |--------------------------------------------------------------------------
            |
            | This is mostly defensive because prepareForValidation()
            | already converts the value to NULL.
            |
            */

            if (
                $this->input('start_type') ===
                    PenaltyRule::START_TYPE_AGREEMENT_DATE &&
                $this->input('start_fiscal_month') !== null
            ) {
                $validator->errors()->add(
                    'start_fiscal_month',
                    'The fiscal month must be empty when the penalty start type is AGREEMENT_DATE.'
                );
            }
        });
    }
}