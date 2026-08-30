<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRevenueServiceRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare incoming data.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            |
            | Empty strings are normalized to null.
            |
            */

            'description' => filled($this->description)
                ? trim($this->description)
                : null,

            /*
            |--------------------------------------------------------------------------
            | Fields
            |--------------------------------------------------------------------------
            |
            | The frontend sends:
            |
            | fields: [...]
            |
            | Never use required_fields here.
            |
            */

            'fields' => $this->fields ?? [],

            /*
            |--------------------------------------------------------------------------
            | Name
            |--------------------------------------------------------------------------
            */

            'name' => is_string($this->name)
                ? trim($this->name)
                : $this->name,

            /*
            |--------------------------------------------------------------------------
            | Active status
            |--------------------------------------------------------------------------
            */

            'is_active' => filter_var(
                $this->is_active,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? true,
        ]);
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Revenue Code
            |--------------------------------------------------------------------------
            */

            'revenue_code_id' => [
                'required',
                'uuid',

                Rule::exists(
                    'revenue_codes',
                    'id'
                )->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],

            /*
            |--------------------------------------------------------------------------
            | Service Name
            |--------------------------------------------------------------------------
            */

            'name' => [
                'required',
                'string',
                'min:2',
                'max:150',

                Rule::unique('revenue_services', 'name')
                    ->where(function ($query) {
                        return $query->where(
                            'revenue_code_id',
                            $this->revenue_code_id
                        );
                    }),
            ],

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Service Type
            |--------------------------------------------------------------------------
            */

            'service_type' => [
                'nullable',
                Rule::in([
                    'REGISTRATION',
                    'ASSESSMENT',
                    'PERMIT',
                    'RENEWAL',
                    'COLLECTION',
                    'PENALTY',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Collection Mode
            |--------------------------------------------------------------------------
            */

            'collection_mode' => [
                'required',
                Rule::in([
                    'ASSESSMENT_ONLY',
                    'FIELD_COLLECTION',
                    'BOTH',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            */

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service Fields
            |--------------------------------------------------------------------------
            |
            | Each field references a canonical BaseField.
            |
            */

            'fields' => [
                'nullable',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Base Field ID
            |--------------------------------------------------------------------------
            */

            'fields.*.base_field_id' => [
                'required',
                'uuid',

                Rule::exists(
                    'base_fields',
                    'id'
                )->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],

            /*
            |--------------------------------------------------------------------------
            | Sort Order
            |--------------------------------------------------------------------------
            */

            'fields.*.sort_order' => [
                'required',
                'integer',
                'min:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Required
            |--------------------------------------------------------------------------
            */

            'fields.*.is_required' => [
                'required',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Service-specific Label
            |--------------------------------------------------------------------------
            */

            'fields.*.label' => [
                'nullable',
                'string',
                'max:150',
            ],

            /*
            |--------------------------------------------------------------------------
            | Help Text
            |--------------------------------------------------------------------------
            */

            'fields.*.help_text' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Validation Rules
            |--------------------------------------------------------------------------
            |
            | Expected structure:
            |
            | {
            |     "min": 0,
            |     "max": 1000,
            |     "minLength": 2,
            |     "maxLength": 100
            | }
            |
            */

            'fields.*.validation_rules' => [
                'nullable',
                'array',
            ],

            'fields.*.validation_rules.min' => [
                'nullable',
                'numeric',
            ],

            'fields.*.validation_rules.max' => [
                'nullable',
                'numeric',
            ],

            'fields.*.validation_rules.minLength' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'fields.*.validation_rules.maxLength' => [
                'nullable',
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
            $fields = $this->input('fields', []);

            if (!is_array($fields)) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate BaseField Protection
            |--------------------------------------------------------------------------
            |
            | A BaseField should only be attached once to a service.
            |
            */

            $baseFieldIds = [];

            foreach ($fields as $index => $field) {
                $baseFieldId =
                    $field['base_field_id'] ?? null;

                if (!$baseFieldId) {
                    continue;
                }

                if (in_array(
                    $baseFieldId,
                    $baseFieldIds,
                    true
                )) {
                    $validator->errors()->add(
                        "fields.$index.base_field_id",
                        "This base field has already been added to the service."
                    );
                }

                $baseFieldIds[] = $baseFieldId;
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate Sort Order Protection
            |--------------------------------------------------------------------------
            |
            | Each configured field should have a unique position.
            |
            */

            $sortOrders = [];

            foreach ($fields as $index => $field) {
                $sortOrder =
                    $field['sort_order'] ?? null;

                if ($sortOrder === null) {
                    continue;
                }

                if (in_array(
                    $sortOrder,
                    $sortOrders,
                    true
                )) {
                    $validator->errors()->add(
                        "fields.$index.sort_order",
                        "This sort order is already used by another field."
                    );
                }

                $sortOrders[] = $sortOrder;
            }

            /*
            |--------------------------------------------------------------------------
            | Validation Rule Consistency
            |--------------------------------------------------------------------------
            |
            | min must not be greater than max.
            |
            */

            foreach ($fields as $index => $field) {
                $rules =
                    $field['validation_rules'] ?? null;

                if (!is_array($rules)) {
                    continue;
                }

                $min =
                    $rules['min'] ?? null;

                $max =
                    $rules['max'] ?? null;

                if (
                    $min !== null &&
                    $max !== null &&
                    is_numeric($min) &&
                    is_numeric($max) &&
                    $min > $max
                ) {
                    $validator->errors()->add(
                        "fields.$index.validation_rules",
                        "The minimum value cannot be greater than the maximum value."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | String Length Rule Consistency
                |--------------------------------------------------------------------------
                */

                $minLength =
                    $rules['minLength'] ?? null;

                $maxLength =
                    $rules['maxLength'] ?? null;

                if (
                    $minLength !== null &&
                    $maxLength !== null &&
                    is_numeric($minLength) &&
                    is_numeric($maxLength) &&
                    $minLength > $maxLength
                ) {
                    $validator->errors()->add(
                        "fields.$index.validation_rules",
                        "The minimum length cannot be greater than the maximum length."
                    );
                }
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
            | Revenue Code
            |--------------------------------------------------------------------------
            */

            'revenue_code_id.required' =>
                'Revenue code is required.',

            'revenue_code_id.uuid' =>
                'Revenue code must be a valid UUID.',

            'revenue_code_id.exists' =>
                'Selected revenue code is invalid or inactive.',

            /*
            |--------------------------------------------------------------------------
            | Service
            |--------------------------------------------------------------------------
            */

            'name.required' =>
                'Service name is required.',

            'name.min' =>
                'Service name must be at least 2 characters.',

            'name.max' =>
                'Service name cannot exceed 150 characters.',

            'name.unique' =>
                'This service already exists under this revenue code.',

            /*
            |--------------------------------------------------------------------------
            | Service Type
            |--------------------------------------------------------------------------
            */

            'service_type.in' =>
                'Invalid service type selected.',

            /*
            |--------------------------------------------------------------------------
            | Collection Mode
            |--------------------------------------------------------------------------
            */

            'collection_mode.required' =>
                'Collection mode is required.',

            'collection_mode.in' =>
                'Invalid collection mode selected.',

            /*
            |--------------------------------------------------------------------------
            | Fields
            |--------------------------------------------------------------------------
            */

            'fields.array' =>
                'Service fields must be an array.',

            'fields.*.base_field_id.required' =>
                'Base field is required.',

            'fields.*.base_field_id.uuid' =>
                'Base field ID must be a valid UUID.',

            'fields.*.base_field_id.exists' =>
                'Selected base field is invalid or inactive.',

            'fields.*.sort_order.required' =>
                'Field sort order is required.',

            'fields.*.sort_order.integer' =>
                'Field sort order must be an integer.',

            'fields.*.sort_order.min' =>
                'Field sort order cannot be negative.',

            'fields.*.is_required.required' =>
                'Field required status is required.',

            'fields.*.is_required.boolean' =>
                'Field required status must be true or false.',

            'fields.*.label.string' =>
                'Field label must be a string.',

            'fields.*.label.max' =>
                'Field label cannot exceed 150 characters.',

            'fields.*.help_text.string' =>
                'Field help text must be a string.',

            'fields.*.help_text.max' =>
                'Field help text cannot exceed 1000 characters.',

            'fields.*.validation_rules.array' =>
                'Field validation rules must be an object.',
        ];
    }
}