<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TariffFormulaVariableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        $tariffRuleId = $this->route('tariffRule')?->id
            ?? $this->route('tariffRule');

        $variableId = $this->route('formulaVariable')?->id
            ?? $this->route('formulaVariable');

        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */


            'variable_name' => [
                'required',
                'string',
                'max:100',
            ],

            'label' => [
                'required',
                'string',
                'max:150',
            ],

            /*
            |--------------------------------------------------------------------------
            | Source
            |--------------------------------------------------------------------------
            */

            'source_type' => [
                'required',
                Rule::in([
                    'BASE_FIELD',
                    'CONSTANT',
                ]),
            ],

            'base_field_id' => [
                'nullable',
                'uuid',
                'exists:base_fields,id',

                Rule::requiredIf(
                    fn () => $this->input('source_type') === 'BASE_FIELD'
                ),
            ],

            'default_value' => [
                'nullable',
                'numeric',

                Rule::requiredIf(
                    fn () => $this->input('source_type') === 'CONSTANT'
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Type
            |--------------------------------------------------------------------------
            */

            'data_type' => [
                'required',
                Rule::in([
                    'NUMBER',
                    'DECIMAL',
                    'PERCENTAGE',
                    'MONEY',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Behaviour
            |--------------------------------------------------------------------------
            */

            'is_required' => [
                'sometimes',
                'boolean',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
        ];
    }

    /**
     * Additional validation.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sourceType = $this->input('source_type');

            if ($sourceType === 'BASE_FIELD') {
                if ($this->filled('default_value')) {
                    $validator->errors()->add(
                        'default_value',
                        'Default value must be null when source type is BASE_FIELD.'
                    );
                }
            }

            if ($sourceType === 'CONSTANT') {
                if ($this->filled('base_field_id')) {
                    $validator->errors()->add(
                        'base_field_id',
                        'Base field must be null when source type is CONSTANT.'
                    );
                }
            }
        });
    }
}