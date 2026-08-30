<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeasurementUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data before validation.
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

        if ($this->has('symbol')) {
            $this->merge([
                'symbol' => trim($this->symbol),
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
                'max:50',
                'alpha_dash',
                Rule::unique('measurement_units', 'code'),
            ],

            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'symbol' => [
                'nullable',
                'string',
                'max:30',
            ],

            'description' => [
                'nullable',
                'string',
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
     * Validation messages.
     */
    public function messages(): array
    {
        return [

            'code.required' => 'Measurement unit code is required.',
            'code.unique' => 'This measurement unit code already exists.',
            'code.alpha_dash' => 'Code may only contain letters, numbers, dashes and underscores.',

            'name.required' => 'Measurement unit name is required.',

            'sort_order.integer' => 'Sort order must be a valid number.',
            'sort_order.min' => 'Sort order cannot be negative.',
        ];
    }

    /**
     * Friendly attribute names.
     */
    public function attributes(): array
    {
        return [

            'code' => 'measurement unit code',

            'name' => 'measurement unit name',

            'symbol' => 'measurement symbol',

            'sort_order' => 'sort order',
        ];
    }
}