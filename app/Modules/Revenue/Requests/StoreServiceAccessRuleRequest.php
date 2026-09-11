<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceAccessRuleRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Service
            |--------------------------------------------------------------------------
            |
            | Injected from route:
            | /services/{service}/access-rules
            |
            */
            'service_id' => [
                'required',
                'uuid',
                Rule::exists('revenue_services', 'id'),
            ],

            /*
            |--------------------------------------------------------------------------
            | Sectors
            |--------------------------------------------------------------------------
            */
            'sectors' => [
                'required',
                'array',
                'min:1',
            ],

            'sectors.*' => [
                'required',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sector ID
            |--------------------------------------------------------------------------
            */
            'sectors.*.sectorId' => [
                'required',
                'uuid',
                Rule::exists('sectors', 'id'),
            ],

            /*
            |--------------------------------------------------------------------------
            | Sector Name
            |--------------------------------------------------------------------------
            |
            | This is display data from the frontend.
            | It is NOT persisted by the backend.
            |
            */
            'sectors.*.sectorName' => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Access Status
            |--------------------------------------------------------------------------
            */
            'sectors.*.isActive' => [
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Additional validation.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sectors = $this->input('sectors', []);

            if (!is_array($sectors)) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate sectors
            |--------------------------------------------------------------------------
            */
            $sectorIds = array_column(
                $sectors,
                'sectorId'
            );

            if (count($sectorIds) !== count(array_unique($sectorIds))) {
                $validator->errors()->add(
                    'sectors',
                    'The same sector cannot be configured more than once.'
                );
            }
        });
    }

    /**
     * Custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'service_id' => 'service',
            'sectors' => 'sectors',
            'sectors.*.sectorId' => 'sector',
            'sectors.*.sectorName' => 'sector name',
            'sectors.*.isActive' => 'access status',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'service_id.required' =>
                'Service is required.',

            'service_id.uuid' =>
                'Service must be a valid UUID.',

            'service_id.exists' =>
                'The selected service does not exist.',

            'sectors.required' =>
                'Please configure at least one sector.',

            'sectors.array' =>
                'Sectors must be an array.',

            'sectors.min' =>
                'Please configure at least one sector.',

            'sectors.*.sectorId.required' =>
                'Sector is required.',

            'sectors.*.sectorId.uuid' =>
                'Sector must be a valid UUID.',

            'sectors.*.sectorId.exists' =>
                'The selected sector does not exist.',

            'sectors.*.sectorName.string' =>
                'Sector name must be a string.',

            'sectors.*.isActive.required' =>
                'Access status is required.',

            'sectors.*.isActive.boolean' =>
                'Access status must be true or false.',
        ];
    }

    /**
     * Prepare request data.
     */
    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Inject service ID from route
        |--------------------------------------------------------------------------
        |
        | Route:
        | /services/{service}/access-rules
        |
        */
        $service = $this->route('service');

        $serviceId = is_object($service)
            ? $service->id
            : $service;

        if ($serviceId) {
            $this->merge([
                'service_id' => $serviceId,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize sector data
        |--------------------------------------------------------------------------
        */
        if ($this->has('sectors') && is_array($this->sectors)) {
            $this->merge([
                'sectors' => array_map(
                    static function (array $sector): array {
                        return [
                            'sectorId' => $sector['sectorId'] ?? null,
                            'sectorName' => $sector['sectorName'] ?? null,
                            'isActive' => filter_var(
                                $sector['isActive'] ?? false,
                                FILTER_VALIDATE_BOOLEAN,
                                FILTER_NULL_ON_FAILURE
                            ),
                        ];
                    },
                    $this->sectors
                ),
            ]);
        }
    }
}