<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTariffRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'base_field_id' => $this->input('base_field_id') ?: null,

            'measurement_unit_id' =>
                $this->input('measurement_unit_id') ?: null,

            'amount' =>
                $this->input('amount') === ''
                    ? null
                    : $this->input('amount'),

            'percentage' =>
                $this->input('percentage') === ''
                    ? null
                    : $this->input('percentage'),

            'min_value' =>
                $this->input('min_value') === ''
                    ? null
                    : $this->input('min_value'),

            'max_value' =>
                $this->input('max_value') === ''
                    ? null
                    : $this->input('max_value'),

            'minimum_amount' =>
                $this->input('minimum_amount') === ''
                    ? null
                    : $this->input('minimum_amount'),

            'maximum_amount' =>
                $this->input('maximum_amount') === ''
                    ? null
                    : $this->input('maximum_amount'),

            'formula' =>
                $this->input('formula') ?: null,

            'conditions' =>
                $this->input('conditions') ?: null,
        ]);
    }

    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Tariff Version
            |--------------------------------------------------------------------------
            */

            'tariff_version_id' => [
                'required',
                'uuid',
                'exists:tariff_versions,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            'service_id' => [
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Rule Identity
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
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Type
            |--------------------------------------------------------------------------
            */

            'calculation_type' => [
                'required',
                Rule::in([
                    'FIXED',
                    'PERCENTAGE',
                    'PER_UNIT',
                    'RANGE',
                    'FORMULA',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Base Calculation Field
            |--------------------------------------------------------------------------
            */

            'base_field_id' => [
                'nullable',
                'uuid',
                'exists:base_fields,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            */

            'measurement_unit_id' => [
                'nullable',
                'uuid',
                'exists:measurement_units,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Priority & Execution
            |--------------------------------------------------------------------------
            */

            'priority' => [
                'required',
                'integer',
                'min:1',
            ],

            'execution_order' => [
                'required',
                'integer',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Range
            |--------------------------------------------------------------------------
            */

            'min_value' => [
                'nullable',
                'numeric',
            ],

            'max_value' => [
                'nullable',
                'numeric',
                'gte:min_value',
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Values
            |--------------------------------------------------------------------------
            */

            'amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'percentage' => [
                'nullable',
                'numeric',
                'between:0,100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Charge Limits
            |--------------------------------------------------------------------------
            */

            'minimum_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'maximum_amount' => [
                'nullable',
                'numeric',
                'gte:minimum_amount',
            ],

            /*
            |--------------------------------------------------------------------------
            | Formula
            |--------------------------------------------------------------------------
            */

            'formula' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | Conditions
            |
            | Example:
            |
            | [
            |     {
            |         "fieldId": "...",
            |         "operator": "less_than_or_equal",
            |         "value": "105"
            |     }
            | ]
            |--------------------------------------------------------------------------
            */

            'conditions' => [
                'nullable',
                'array',
            ],

            'conditions.*' => [
                'required',
                'array',
            ],

            'conditions.*.fieldId' => [
                'required',
                'uuid',
                'exists:base_fields,id',
            ],

            'conditions.*.operator' => [
                'required',
                Rule::in([
                    'equals',
                    'not_equals',
                    'contains',
                    'greater_than',
                    'greater_than_or_equal',
                    'less_than',
                    'less_than_or_equal',
                ]),
            ],

            'conditions.*.value' => [
                'required',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Rounding
            |--------------------------------------------------------------------------
            */

            'rounding_rule' => [
                'required',
                Rule::in([
                    'NONE',
                    'ROUND_UP',
                    'ROUND_DOWN',
                    'NEAREST',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => [
                'required',
                'boolean',
            ],
        ];
    }
}