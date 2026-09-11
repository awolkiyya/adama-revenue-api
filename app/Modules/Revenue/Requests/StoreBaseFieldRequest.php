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
        $data = [];

        if ($this->has('code')) {
            $data['code'] = strtoupper(trim((string) $this->code));
        }

        if ($this->has('name')) {
            $data['name'] = trim((string) $this->name);
        }

        if ($this->has('description')) {
            $data['description'] = trim((string) $this->description);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize options
        |--------------------------------------------------------------------------
        |
        | Options are only:
        | - value
        | - label
        | - sort_order
        | - is_default
        |
        | There is intentionally NO option-level is_active.
        |
        */

        if ($this->has('options') && is_array($this->options)) {
            $data['options'] = collect($this->options)
                ->map(function ($option, $index) {
                    return [
                        'value' => isset($option['value'])
                            ? trim((string) $option['value'])
                            : null,

                        'label' => isset($option['label'])
                            ? trim((string) $option['label'])
                            : null,

                        'sort_order' => isset($option['sort_order'])
                            ? (int) $option['sort_order']
                            : $index,

                        'is_default' => isset($option['is_default'])
                            ? (bool) $option['is_default']
                            : false,
                    ];
                })
                ->values()
                ->toArray();
        }

        if (!empty($data)) {
            $this->merge($data);
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
                    'PERCENTAGE',
                    'TEXT',
                    'BOOLEAN',
                    'DATE',
                    'FILE',
                    'CHECKBOX',
                    'RADIO',
                    'SELECT',
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

            /*
            |--------------------------------------------------------------------------
            | Sort Order
            |--------------------------------------------------------------------------
            */

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            |
            | SELECT / RADIO / CHECKBOX require options.
            |
            */

            'options' => [
                'nullable',
                'array',
                'required_if:data_type,SELECT,RADIO,CHECKBOX',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Individual Options
            |--------------------------------------------------------------------------
            */

            'options.*' => [
                'required',
                'array',
            ],

            'options.*.value' => [
                'required',
                'string',
                'max:100',
            ],

            'options.*.label' => [
                'required',
                'string',
                'max:150',
            ],

            'options.*.sort_order' => [
                'required',
                'integer',
                'min:0',
            ],

            'options.*.is_default' => [
                'required',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Option-level fields that must NOT exist
            |--------------------------------------------------------------------------
            |
            | We intentionally removed option-level is_active.
            |
            */

            'options.*.is_active' => [
                'prohibited',
            ],
        ];
    }

    /**
     * Additional validation after standard rules.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $dataType = $this->input('data_type');

                /*
                |--------------------------------------------------------------------------
                | Non-option data types must not receive options
                |--------------------------------------------------------------------------
                */

                if (
                    in_array($dataType, [
                        'NUMBER',
                        'DECIMAL',
                        'PERCENTAGE',
                        'TEXT',
                        'BOOLEAN',
                        'DATE',
                        'FILE',
                    ], true)
                    && $this->filled('options')
                ) {
                    $validator->errors()->add(
                        'options',
                        "Options are not allowed for {$dataType} data type."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Option values must be unique
                |--------------------------------------------------------------------------
                */

                if (
                    in_array($dataType, [
                        'SELECT',
                        'RADIO',
                        'CHECKBOX',
                    ], true)
                ) {
                    $options = $this->input('options', []);

                    $values = collect($options)
                        ->pluck('value')
                        ->filter(fn ($value) => $value !== null && $value !== '')
                        ->map(fn ($value) => mb_strtolower(trim((string) $value)))
                        ->values();

                    if ($values->count() !== $values->unique()->count()) {
                        $validator->errors()->add(
                            'options',
                            'Option values must be unique.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Labels should also be unique
                    |--------------------------------------------------------------------------
                    */

                    $labels = collect($options)
                        ->pluck('label')
                        ->filter(fn ($label) => $label !== null && $label !== '')
                        ->map(fn ($label) => mb_strtolower(trim((string) $label)))
                        ->values();

                    if ($labels->count() !== $labels->unique()->count()) {
                        $validator->errors()->add(
                            'options',
                            'Option labels must be unique.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Only one default option
                    |--------------------------------------------------------------------------
                    */

                    $defaultCount = collect($options)
                        ->where('is_default', true)
                        ->count();

                    if ($defaultCount > 1) {
                        $validator->errors()->add(
                            'options',
                            'Only one option can be selected as the default.'
                        );
                    }
                }
            },
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
            | Identity
            |--------------------------------------------------------------------------
            */

            'code.required' => 'Base field code is required.',

            'code.unique' => 'This base field code already exists.',

            'code.alpha_dash' =>
                'The code may only contain letters, numbers, dashes and underscores.',

            'name.required' =>
                'Base field name is required.',

            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            */

            'measurement_unit_id.exists' =>
                'The selected measurement unit does not exist.',

            'measurement_unit_id.uuid' =>
                'The selected measurement unit ID must be a valid UUID.',

            /*
            |--------------------------------------------------------------------------
            | Data Type
            |--------------------------------------------------------------------------
            */

            'data_type.required' =>
                'Data type is required.',

            'data_type.in' =>
                'The selected data type is invalid.',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active.boolean' =>
                'Active status must be true or false.',

            /*
            |--------------------------------------------------------------------------
            | Sort Order
            |--------------------------------------------------------------------------
            */

            'sort_order.integer' =>
                'Sort order must be an integer.',

            'sort_order.min' =>
                'Sort order cannot be negative.',

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            */

            'options.array' =>
                'Options must be provided as an array.',

            'options.required_if' =>
                'Options are required for SELECT, RADIO, and CHECKBOX fields.',

            'options.min' =>
                'At least one option is required.',

            /*
            |--------------------------------------------------------------------------
            | Individual Options
            |--------------------------------------------------------------------------
            */

            'options.*.array' =>
                'Each option must be an object.',

            'options.*.value.required' =>
                'Each option must have a value.',

            'options.*.value.string' =>
                'Option value must be a string.',

            'options.*.value.max' =>
                'Option value cannot exceed 100 characters.',

            'options.*.label.required' =>
                'Each option must have a label.',

            'options.*.label.string' =>
                'Option label must be a string.',

            'options.*.label.max' =>
                'Option label cannot exceed 150 characters.',

            'options.*.sort_order.required' =>
                'Each option must have a sort order.',

            'options.*.sort_order.integer' =>
                'Option sort order must be an integer.',

            'options.*.sort_order.min' =>
                'Option sort order cannot be negative.',

            'options.*.is_default.required' =>
                'Each option must specify whether it is the default.',

            'options.*.is_default.boolean' =>
                'Option default status must be true or false.',

            'options.*.is_active.prohibited' =>
                'Option-level active status is not supported.',
        ];
    }

    /**
     * Friendly attribute names.
     */
    public function attributes(): array
    {
        return [

            'code' =>
                'base field code',

            'name' =>
                'base field name',

            'measurement_unit_id' =>
                'measurement unit',

            'data_type' =>
                'data type',

            'is_active' =>
                'active status',

            'sort_order' =>
                'sort order',

            'options' =>
                'field options',

            'options.*.value' =>
                'option value',

            'options.*.label' =>
                'option label',

            'options.*.sort_order' =>
                'option sort order',

            'options.*.is_default' =>
                'option default status',
        ];
    }
}