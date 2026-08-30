<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRevenueServiceRequest extends FormRequest
{
    /**
     * Authorize request.
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
        $service = $this->route('service');

        /*
        |--------------------------------------------------------------------------
        | Resolve service ID safely
        |--------------------------------------------------------------------------
        */

        $serviceId = is_object($service)
            ? $service->id
            : $service;

        /*
        |--------------------------------------------------------------------------
        | Resolve current revenue code
        |--------------------------------------------------------------------------
        |
        | Used by the unique service-name rule when revenue_code_id is not
        | included in the update request.
        |
        */

        $currentRevenueCodeId = is_object($service)
            ? $service->revenue_code_id
            : null;

        $revenueCodeId =
            $this->input(
                'revenue_code_id',
                $currentRevenueCodeId
            );

        return [

            /*
            |--------------------------------------------------------------------------
            | Revenue Code
            |--------------------------------------------------------------------------
            */

            'revenue_code_id' => [
                'sometimes',
                'required',
                'uuid',

                Rule::exists(
                    'revenue_codes',
                    'id'
                )->where(
                    'is_active',
                    true
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Service Name
            |--------------------------------------------------------------------------
            */

            'name' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:150',

                Rule::unique(
                    'revenue_services',
                    'name'
                )
                    ->where(
                        function ($query) use ($revenueCodeId) {
                            return $query->where(
                                'revenue_code_id',
                                $revenueCodeId
                            );
                        }
                    )
                    ->ignore($serviceId),
            ],

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description' => [
                'sometimes',
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
                'sometimes',
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
                'sometimes',
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
            | These are NOT arbitrary dynamic fields anymore.
            |
            | Each field must reference a canonical BaseField.
            |
            */

            'fields' => [
                'sometimes',
                'nullable',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Base Field Reference
            |--------------------------------------------------------------------------
            */

            'fields.*.base_field_id' => [
                'required',
                'uuid',

                Rule::exists(
                    'base_fields',
                    'id'
                )->where(
                    'is_active',
                    true
                ),
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
            | Service-Specific Label
            |--------------------------------------------------------------------------
            */

            'fields.*.label' => [
                'sometimes',
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
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Validation Rules
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | {
            |     "min": 0,
            |     "max": 10000,
            |     "minLength": 2,
            |     "maxLength": 100
            | }
            |
            */

            'fields.*.validation_rules' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'fields.*.validation_rules.min' => [
                'sometimes',
                'numeric',
            ],

            'fields.*.validation_rules.max' => [
                'sometimes',
                'numeric',
            ],

            'fields.*.validation_rules.minLength' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'fields.*.validation_rules.maxLength' => [
                'sometimes',
                'integer',
                'min:0',
            ],
        ];
    }

    /**
     * Additional business validation.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            $fields = $this->input('fields', []);

            /*
            |--------------------------------------------------------------------------
            | Nothing to validate if fields were not supplied.
            |--------------------------------------------------------------------------
            |
            | Important for PATCH-style updates.
            |
            */

            if (!is_array($fields)) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate BaseField Detection
            |--------------------------------------------------------------------------
            |
            | A BaseField should only appear once in a RevenueService.
            |
            */

            $baseFieldIds = [];

            foreach ($fields as $index => $field) {

                $baseFieldId =
                    $field['base_field_id'] ?? null;

                if (!$baseFieldId) {
                    continue;
                }

                if (
                    in_array(
                        $baseFieldId,
                        $baseFieldIds,
                        true
                    )
                ) {
                    $validator->errors()->add(
                        "fields.$index.base_field_id",
                        "This base field has already been added to this service."
                    );
                }

                $baseFieldIds[] = $baseFieldId;
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate Sort Order Detection
            |--------------------------------------------------------------------------
            |
            | Two service fields should not have the same position.
            |
            */

            $sortOrders = [];

            foreach ($fields as $index => $field) {

                $sortOrder =
                    $field['sort_order'] ?? null;

                if ($sortOrder === null) {
                    continue;
                }

                if (
                    in_array(
                        $sortOrder,
                        $sortOrders,
                        true
                    )
                ) {
                    $validator->errors()->add(
                        "fields.$index.sort_order",
                        "Duplicate field sort order is not allowed."
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

                if (
                    isset($rules['min']) &&
                    isset($rules['max']) &&
                    $rules['min'] > $rules['max']
                ) {
                    $validator->errors()->add(
                        "fields.$index.validation_rules",
                        "Minimum value cannot be greater than maximum value."
                    );
                }

                if (
                    isset($rules['minLength']) &&
                    isset($rules['maxLength']) &&
                    $rules['minLength'] > $rules['maxLength']
                ) {
                    $validator->errors()->add(
                        "fields.$index.validation_rules",
                        "Minimum length cannot be greater than maximum length."
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Collection Mode Business Rules
            |--------------------------------------------------------------------------
            |
            | If fields are explicitly supplied as part of an update,
            | validate the desired configuration.
            |
            */

            if (
                $this->filled('collection_mode')
                &&
                in_array(
                    $this->collection_mode,
                    [
                        'FIELD_COLLECTION',
                        'BOTH',
                    ],
                    true
                )
                &&
                empty($fields)
            ) {
                $validator->errors()->add(
                    'fields',
                    'This collection mode requires at least one service field.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Assessment Business Rule
            |--------------------------------------------------------------------------
            */

            if (
                $this->input('service_type') === 'ASSESSMENT'
                &&
                $this->has('fields')
                &&
                empty($fields)
            ) {
                $validator->errors()->add(
                    'fields',
                    'Assessment services must define at least one service field.'
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
            | Revenue Code
            |--------------------------------------------------------------------------
            */

            'revenue_code_id.required' =>
                'Revenue code is required.',

            'revenue_code_id.uuid' =>
                'Revenue code must be a valid UUID.',

            'revenue_code_id.exists' =>
                'The selected revenue code is invalid or inactive.',

            /*
            |--------------------------------------------------------------------------
            | Name
            |--------------------------------------------------------------------------
            */

            'name.required' =>
                'Service name is required.',

            'name.min' =>
                'Service name must be at least 3 characters.',

            'name.max' =>
                'Service name cannot exceed 150 characters.',

            'name.unique' =>
                'A service with this name already exists under this revenue code.',

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description.string' =>
                'Description must be a valid string.',

            'description.max' =>
                'Description cannot exceed 1000 characters.',

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

            /*
            |--------------------------------------------------------------------------
            | Base Field
            |--------------------------------------------------------------------------
            */

            'fields.*.base_field_id.required' =>
                'Each service field must reference a base field.',

            'fields.*.base_field_id.uuid' =>
                'Each base field ID must be a valid UUID.',

            'fields.*.base_field_id.exists' =>
                'One or more selected base fields are invalid or inactive.',

            /*
            |--------------------------------------------------------------------------
            | Sort Order
            |--------------------------------------------------------------------------
            */

            'fields.*.sort_order.required' =>
                'Each service field requires a sort order.',

            'fields.*.sort_order.integer' =>
                'Field sort order must be an integer.',

            'fields.*.sort_order.min' =>
                'Field sort order cannot be negative.',

            /*
            |--------------------------------------------------------------------------
            | Required
            |--------------------------------------------------------------------------
            */

            'fields.*.is_required.required' =>
                'Each service field must specify whether it is required.',

            'fields.*.is_required.boolean' =>
                'Field required value must be true or false.',

            /*
            |--------------------------------------------------------------------------
            | Label
            |--------------------------------------------------------------------------
            */

            'fields.*.label.string' =>
                'Field label must be a string.',

            'fields.*.label.max' =>
                'Field label cannot exceed 150 characters.',

            /*
            |--------------------------------------------------------------------------
            | Help Text
            |--------------------------------------------------------------------------
            */

            'fields.*.help_text.string' =>
                'Field help text must be a string.',

            'fields.*.help_text.max' =>
                'Field help text cannot exceed 1000 characters.',

            /*
            |--------------------------------------------------------------------------
            | Validation Rules
            |--------------------------------------------------------------------------
            */

            'fields.*.validation_rules.array' =>
                'Validation rules must be an object.',

            'fields.*.validation_rules.min.numeric' =>
                'Minimum validation value must be numeric.',

            'fields.*.validation_rules.max.numeric' =>
                'Maximum validation value must be numeric.',

            'fields.*.validation_rules.minLength.integer' =>
                'Minimum length must be an integer.',

            'fields.*.validation_rules.maxLength.integer' =>
                'Maximum length must be an integer.',

        ];
    }
}