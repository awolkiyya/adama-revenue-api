<?php

declare(strict_types=1);

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBaseFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        /*
        |--------------------------------------------------------------------------
        | Normalize Identity Fields
        |--------------------------------------------------------------------------
        */

        if ($this->has('code')) {
            $data['code'] = strtoupper(
                trim((string) $this->input('code'))
            );
        }

        if ($this->has('name')) {
            $data['name'] = trim(
                (string) $this->input('name')
            );
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
        | Supported option properties:
        | - id
        | - value
        | - label
        | - sort_order
        | - is_default
        |
        | Unsupported properties are preserved for prohibited validation.
        |
        */

        if (
            $this->has('options')
            && is_array($this->input('options'))
        ) {
            $data['options'] = collect($this->input('options'))
                ->map(function ($option, $index) {
                    if (!is_array($option)) {
                        return $option;
                    }

                    return [
                        'id' => $option['id'] ?? null,

                        'value' => isset($option['value'])
                            ? trim((string) $option['value'])
                            : null,

                        'label' => isset($option['label'])
                            ? trim((string) $option['label'])
                            : null,

                        'sort_order' => isset($option['sort_order'])
                            ? (int) $option['sort_order']
                            : $index,

                        'is_default' => filter_var(
                            $option['is_default'] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),

                        ...array_diff_key(
                            $option,
                            array_flip([
                                'id',
                                'value',
                                'label',
                                'sort_order',
                                'is_default',
                            ])
                        ),
                    ];
                })
                ->values()
                ->all();
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    private function requiresOptions(): bool
    {
        return in_array(
            $this->input('data_type'),
            ['SELECT', 'RADIO', 'CHECKBOX'],
            true
        );
    }

    public function rules(): array
    {
        $baseField = $this->route('base_field')
            ?? $this->route('baseField')
            ?? $this->route('id');

        $requiresOptions = $this->requiresOptions();

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
            | Input Source
            |--------------------------------------------------------------------------
            |
            | Optional during updates so clients that do not send this
            | property can continue updating other base field properties.
            |
            */

            'input_source' => [
                'sometimes',
                'required',
                Rule::in([
                    'MANUAL',
                    'AI',
                    'MANUAL_OR_AI',
                ]),
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
            */

            'options' => [
                'present',
                'array',
                $requiresOptions ? 'min:1' : 'max:0',
            ],

            'options.*' => [
                'required',
                'array',
            ],

            'options.*.id' => [
                'sometimes',
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
            | Unsupported Option Properties
            |--------------------------------------------------------------------------
            */

            'options.*.is_active' => [
                'prohibited',
            ],

            'options.*.description' => [
                'prohibited',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dataType = $this->input('data_type');
            $options = $this->input('options', []);

            if (!is_array($options) || $options === []) {
                return;
            }

            $optionDataTypes = [
                'SELECT',
                'RADIO',
                'CHECKBOX',
            ];

            if (!in_array($dataType, $optionDataTypes, true)) {
                return;
            }

            $values = [];
            $labels = [];
            $defaultCount = 0;

            foreach ($options as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $value = isset($option['value'])
                    ? trim((string) $option['value'])
                    : '';

                $label = isset($option['label'])
                    ? trim((string) $option['label'])
                    : '';

                /*
                |--------------------------------------------------------------------------
                | Option Values Must Be Unique
                |--------------------------------------------------------------------------
                */

                if ($value !== '') {
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
                | Option Labels Must Be Unique
                |--------------------------------------------------------------------------
                */

                if ($label !== '') {
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

                if (
                    filter_var(
                        $option['is_default'] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    )
                ) {
                    $defaultCount++;
                }
            }

            if ($defaultCount > 1) {
                $validator->errors()->add(
                    'options',
                    'Only one option can be selected as the default.'
                );
            }
        });
    }

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
            | Input Source
            |--------------------------------------------------------------------------
            */

            'input_source.required' =>
                'Input source is required when provided.',
            'input_source.in' =>
                'The selected input source is invalid.',

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
            | Status and Sort Order
            |--------------------------------------------------------------------------
            */

            'is_active.boolean' =>
                'Active status must be true or false.',
            'sort_order.integer' =>
                'Sort order must be an integer.',
            'sort_order.min' =>
                'Sort order cannot be negative.',

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            */

            'options.present' =>
                'The options field must be provided.',
            'options.array' =>
                'Options must be provided as an array.',
            'options.min' =>
                'At least one option is required for SELECT, RADIO, and CHECKBOX fields.',
            'options.max' =>
                'Options are not allowed for this data type.',

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
            | Unsupported Option Fields
            |--------------------------------------------------------------------------
            */

            'options.*.is_active.prohibited' =>
                'Option-level active status is not supported.',
            'options.*.description.prohibited' =>
                'Option-level description is not supported.',
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'base field code',
            'name' => 'base field name',
            'description' => 'description',
            'input_source' => 'input source',
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