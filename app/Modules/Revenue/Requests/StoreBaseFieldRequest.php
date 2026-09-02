<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBaseFieldRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper(trim($this->code)),
            ]);
        }

        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->name),
            ]);
        }

        if ($this->has('description')) {
            $this->merge([
                'description' => trim($this->description),
            ]);
        }
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'code' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('base_fields', 'code'),
            ],

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
            | Data Type
            |--------------------------------------------------------------------------
            */

            'data_type' => [
                'required',
                Rule::in([
                    'NUMBER',
                    'DECIMAL',
                    'TEXT',
                    'BOOLEAN',
                    'DATE',
                    "FILE",
                    "CHECKBOX",
                    "RADIO"
                ]),
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

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [

            'code.required' => 'Base field code is required.',
            'code.unique' => 'This base field code already exists.',
            'code.alpha_dash' => 'The code may only contain letters, numbers, dashes and underscores.',

            'name.required' => 'Base field name is required.',

            'measurement_unit_id.exists' => 'The selected measurement unit does not exist.',

            'data_type.required' => 'Data type is required.',
            'data_type.in' => 'The selected data type is invalid.',

            'sort_order.integer' => 'Sort order must be an integer.',
            'sort_order.min' => 'Sort order cannot be negative.',
        ];
    }

    /**
     * Friendly attribute names.
     */
    public function attributes(): array
    {
        return [

            'code' => 'base field code',

            'name' => 'base field name',

            'measurement_unit_id' => 'measurement unit',

            'data_type' => 'data type',

            'sort_order' => 'sort order',
        ];
    }
}