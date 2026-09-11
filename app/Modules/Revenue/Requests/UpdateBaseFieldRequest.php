<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBaseFieldRequest extends FormRequest
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

        /*
        |--------------------------------------------------------------------------
        | Normalize Identity Fields
        |--------------------------------------------------------------------------
        */

        if ($this->has('code')) {
            $data['code'] = strtoupper(trim((string) $this->input('code')));
        }

        if ($this->has('name')) {
            $data['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('description')) {
            $description = $this->input('description');

            $data['description'] = is_string($description)
                ? trim($description)
                : $description;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Options
        |--------------------------------------------------------------------------
        |
        | Only these option fields are supported:
        |
        | - value
        | - label
        | - sort_order
        | - is_default
        |
        | Option-level is_active and description are intentionally removed.
        |
        */

        if ($this->has('options') && is_array($this->input('options'))) {
            $data['options'] = collect($this->input('options'))
                ->map(function ($option) {
                    if (!is_array($option)) {
                        return $option;
                    }

                    return [
                        // Preserve ID during update when supplied.
                        'id' => $option['id'] ?? null,

                        'value' => isset($option['value'])
                            ? trim((string) $option['value'])
                            : null,

                        'label' => isset($option['label'])
                            ? trim((string) $option['label'])
                            : null,

                        'sort_order' => $option['sort_order'] ?? null,

                        'is_default' => $option['is_default'] ?? false,
                    ];
                })
                ->values()
                ->all();
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
        $baseField = $this->route('base_field')
            ?? $this->route('baseField')
            ?? $this->route('id');

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

                Rule::unique('base_fields', 'code')
                    ->ignore($baseField),
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
                    'SELECT',
                    'FILE',
                    'CHECKBOX',
                    'RADIO',
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

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            |
            | Options are required for:
            |
            | SELECT
            | RADIO
            | CHECKBOX
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

            'options.*.id' => [
                'nullable',
                'uuid',
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
            | Removed Option Fields
            |--------------------------------------------------------------------------
            |
            | These are intentionally prohibited.
            |
            */

            'options.*.is_active' => [
                'prohibited',
            ],

            'options.*.description' => [
                'prohibited',
            ],
        ];
    }

    /**
     * Additional validation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {

            $dataType = $this->input('data_type');
            $options = $this->input('options');

            /*
            |--------------------------------------------------------------------------
            | Option-Based Data Types
            |--------------------------------------------------------------------------
            */

            $optionDataTypes = [
                'SELECT',
                'RADIO',
                'CHECKBOX',
            ];

            /*
            |--------------------------------------------------------------------------
            | Non-Option Data Types
            |--------------------------------------------------------------------------
            */

            $nonOptionDataTypes = [
                'NUMBER',
                'DECIMAL',
                'PERCENTAGE',
                'TEXT',
                'BOOLEAN',
                'DATE',
                'FILE',
            ];

            /*
            |--------------------------------------------------------------------------
            | Options Must Not Exist for Non-Option Fields
            |--------------------------------------------------------------------------
            */

            if (
                in_array($dataType, $nonOptionDataTypes, true)
                && is_array($options)
                && count($options) > 0
            ) {
                $validator->errors()->add(
                    'options',
                    "Options are not allowed for {$dataType} data type."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Options Required for Option-Based Fields
            |--------------------------------------------------------------------------
            */

            if (
                in_array($dataType, $optionDataTypes, true)
                && (!is_array($options) || count($options) === 0)
            ) {
                $validator->errors()->add(
                    'options',
                    "At least one option is required for {$dataType} data type."
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Nothing More to Validate
            |--------------------------------------------------------------------------
            */

            if (!is_array($options) || empty($options)) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Unique Option Values
            |--------------------------------------------------------------------------
            */

            $values = [];

            foreach ($options as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $value = isset($option['value'])
                    ? trim((string) $option['value'])
                    : null;

                if ($value === null || $value === '') {
                    continue;
                }

                $normalizedValue = mb_strtolower($value);

                if (in_array($normalizedValue, $values, true)) {
                    $validator->errors()->add(
                        "options.{$index}.value",
                        'Option values must be unique.'
                    );
                }

                $values[] = $normalizedValue;
            }

            /*
            |--------------------------------------------------------------------------
            | Unique Option Labels
            |--------------------------------------------------------------------------
            */

            $labels = [];

            foreach ($options as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $label = isset($option['label'])
                    ? trim((string) $option['label'])
                    : null;

                if ($label === null || $label === '') {
                    continue;
                }

                $normalizedLabel = mb_strtolower($label);

                if (in_array($normalizedLabel, $labels, true)) {
                    $validator->errors()->add(
                        "options.{$index}.label",
                        'Option labels must be unique.'
                    );
                }

                $labels[] = $normalizedLabel;
            }

            /*
            |--------------------------------------------------------------------------
            | Only One Default Option
            |--------------------------------------------------------------------------
            */

            $defaultCount = collect($options)
                ->filter(function ($option) {
                    return is_array($option)
                        && filter_var(
                            $option['is_default'] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        );
                })
                ->count();

            if ($defaultCount > 1) {
                $validator->errors()->add(
                    'options',
                    'Only one option can be selected as the default.'
                );
            }
        });
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

            'name.required' => 'Base field name is required.',

            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            */

            'measurement_unit_id.uuid' =>
                'The measurement unit ID must be a valid UUID.',

            'measurement_unit_id.exists' =>
                'The selected measurement unit does not exist.',

            /*
            |--------------------------------------------------------------------------
            | Data Type
            |--------------------------------------------------------------------------
            */

            'data_type.required' => 'Data type is required.',

            'data_type.in' => 'The selected data type is invalid.',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active.boolean' => 'Active status must be true or false.',

            'sort_order.integer' => 'Sort order must be an integer.',

            'sort_order.min' => 'Sort order cannot be negative.',

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            */

            'options.array' => 'Options must be provided as an array.',

            'options.min' =>
                'At least one option is required.',

            'options.required_if' =>
                'Options are required for SELECT, RADIO and CHECKBOX fields.',

            /*
            |--------------------------------------------------------------------------
            | Individual Options
            |--------------------------------------------------------------------------
            */

            'options.*.array' =>
                'Each option must be a valid object.',

            'options.*.id.uuid' =>
                'The option ID must be a valid UUID.',

            'options.*.value.required' =>
                'Option value is required.',

            'options.*.value.max' =>
                'Option value may not exceed 100 characters.',

            'options.*.label.required' =>
                'Option label is required.',

            'options.*.label.max' =>
                'Option label may not exceed 150 characters.',

            'options.*.sort_order.required' =>
                'Option sort order is required.',

            'options.*.sort_order.integer' =>
                'Option sort order must be an integer.',

            'options.*.sort_order.min' =>
                'Option sort order cannot be negative.',

            'options.*.is_default.required' =>
                'Option default status is required.',

            'options.*.is_default.boolean' =>
                'Option default status must be true or false.',

            /*
            |--------------------------------------------------------------------------
            | Removed Fields
            |--------------------------------------------------------------------------
            */

            'options.*.is_active.prohibited' =>
                'Option-level active status is not supported.',

            'options.*.description.prohibited' =>
                'Option-level description is not supported.',
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

            'description' => 'description',

            'measurement_unit_id' => 'measurement unit',

            'data_type' => 'data type',

            'is_active' => 'active status',

            'sort_order' => 'sort order',

            'options' => 'options',

            'options.*.id' => 'option ID',

            'options.*.value' => 'option value',

            'options.*.label' => 'option label',

            'options.*.sort_order' => 'option sort order',

            'options.*.is_default' => 'option default status',
        ];
    }
}