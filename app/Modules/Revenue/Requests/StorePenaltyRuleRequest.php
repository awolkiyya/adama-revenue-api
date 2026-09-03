<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePenaltyRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\PenaltyRule::class)
            ?? false;
    }

    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Scope
            |--------------------------------------------------------------------------
            */

            'revenue_service_id' => [
                'nullable',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'legal_reference' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation
            |--------------------------------------------------------------------------
            */

            'calculation_type' => [
                'required',
                Rule::in([
                    'FIXED',
                    'PERCENTAGE',
                    'PROGRESSIVE',
                ]),
            ],

            'fixed_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,2',
                'required_if:calculation_type,FIXED',
            ],

            'initial_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,4',
                'required_if:calculation_type,PERCENTAGE',
                'required_if:calculation_type,PROGRESSIVE',
            ],

            'increment_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,4',
                'required_if:calculation_type,PROGRESSIVE',
            ],

            'maximum_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,4',
                'required_if:calculation_type,PROGRESSIVE',
            ],

            /*
            |--------------------------------------------------------------------------
            | Start Configuration
            |--------------------------------------------------------------------------
            */

            'start_type' => [
                'required',
                Rule::in([
                    'DUE_DATE',
                    'AGREEMENT_START',
                    'AFTER_GRACE_PERIOD',
                    'FISCAL_YEAR_START',
                ]),
            ],

            'start_offset_value' => [
                'required',
                'integer',
                'min:0',
            ],

            'start_offset_unit' => [
                'required',
                Rule::in([
                    'DAY',
                    'MONTH',
                    'YEAR',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Progressive Period
            |--------------------------------------------------------------------------
            */

            'increment_period' => [
                'nullable',
                Rule::in([
                    'DAY',
                    'MONTH',
                    'YEAR',
                ]),
                'required_if:calculation_type,PROGRESSIVE',
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            'calculation_basis' => [
                'required',
                Rule::in([
                    'PRINCIPAL',
                    'OUTSTANDING',
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
            'fixed_amount.required_if' =>
                'Fixed amount is required when calculation type is FIXED.',

            'initial_rate.required_if' =>
                'Initial rate is required for percentage and progressive penalties.',

            'increment_rate.required_if' =>
                'Increment rate is required for progressive penalties.',

            'maximum_rate.required_if' =>
                'Maximum rate is required for progressive penalties.',

            'increment_period.required_if' =>
                'Increment period is required for progressive penalties.',

            'effective_to.after_or_equal' =>
                'The effective end date must be on or after the effective start date.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (
            $this->calculation_type === 'FIXED'
        ) {
            $this->merge([
                'initial_rate' => null,
                'increment_rate' => null,
                'maximum_rate' => null,
                'increment_period' => null,
            ]);
        }

        if (
            $this->calculation_type === 'PERCENTAGE'
        ) {
            $this->merge([
                'increment_rate' => null,
                'maximum_rate' => null,
                'increment_period' => null,
            ]);
        }

        if (
            $this->calculation_type === 'PROGRESSIVE'
        ) {
            $this->merge([
                'fixed_amount' => null,
            ]);
        }
    }
}