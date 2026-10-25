<?php

declare(strict_types=1);

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBaseFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

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
            $description = trim(
                (string) $this->input('description')
            );

            $data['description'] = $description === ''
                ? null
                : $description;
        }

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
                            ? filter_var(
                                $option['is_default'],
                                FILTER_VALIDATE_BOOLEAN
                            )
                            : false,

                        ...array_diff_key(
                            $option,
                            array_flip([
                                'value',
                                'label',
                                'sort_order',
                                'is_default',
                            ])
                        ),
                    ];
                })
                ->values()
                ->toArray();
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
            | Input Source
            |--------------------------------------------------------------------------
            |
            | Omission is supported for backward compatibility.
            | The database default is MANUAL.
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

    public function after(): array
    {
        return [
            function ($validator): void {
                if (!in_array(
                    $this->input('data_type'),
                    ['SELECT', 'RADIO', 'CHECKBOX'],
                    true
                )) {
                    return;
                }

                $options = $this->input('options', []);

                if (!is_array($options) || $options === []) {
                    return;
                }

                $values = collect($options)
                    ->filter(fn ($option) => is_array($option))
                    ->pluck('value')
                    ->filter(
                        fn ($value) => $value !== null && $value !== ''
                    )
                    ->map(
                        fn ($value) => mb_strtolower(
                            trim((string) $value)
                        )
                    )
                    ->values();

                if ($values->count() !== $values->unique()->count()) {
                    $validator->errors()->add(
                        'options',
                        'Option values must be unique.'
                    );
                }

                $labels = collect($options)
                    ->filter(fn ($option) => is_array($option))
                    ->pluck('label')
                    ->filter(
                        fn ($label) => $label !== null && $label !== ''
                    )
                    ->map(
                        fn ($label) => mb_strtolower(
                            trim((string) $label)
                        )
                    )
                    ->values();

                if ($labels->count() !== $labels->unique()->count()) {
                    $validator->errors()->add(
                        'options',
                        'Option labels must be unique.'
                    );
                }

                $defaultCount = collect($options)
                    ->filter(
                        fn ($option) => is_array($option)
                            && filter_var(
                                $option['is_default'] ?? false,
                                FILTER_VALIDATE_BOOLEAN
                            )
                    )
                    ->count();

                if ($defaultCount > 1) {
                    $validator->errors()->add(
                        'options',
                        'Only one option can be selected as the default.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Base field code is required.',
            'code.unique' => 'This base field code already exists.',
            'code.alpha_dash' =>
                'The code may only contain letters, numbers, dashes and underscores.',
            'name.required' => 'Base field name is required.',

            'input_source.required' =>
                'Input source is required when provided.',
            'input_source.in' =>
                'The selected input source is invalid.',

            'measurement_unit_id.exists' =>
                'The selected measurement unit does not exist.',
            'measurement_unit_id.uuid' =>
                'The selected measurement unit ID must be a valid UUID.',

            'data_type.required' => 'Data type is required.',
            'data_type.in' => 'The selected data type is invalid.',

            'is_active.boolean' =>
                'Active status must be true or false.',

            'sort_order.integer' =>
                'Sort order must be an integer.',
            'sort_order.min' =>
                'Sort order cannot be negative.',

            'options.present' =>
                'The options field must be provided.',
            'options.array' =>
                'Options must be provided as an array.',
            'options.min' =>
                'At least one option is required for SELECT, RADIO, and CHECKBOX fields.',
            'options.max' =>
                'Options are not allowed for this data type.',

            'options.*.required' =>
                'Each option must be provided.',
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
            'options' => 'field options',
            'options.*.value' => 'option value',
            'options.*.label' => 'option label',
            'options.*.sort_order' => 'option sort order',
            'options.*.is_default' => 'option default status',
        ];
    }
}
