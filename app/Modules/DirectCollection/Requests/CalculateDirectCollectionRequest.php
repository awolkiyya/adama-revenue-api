<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateDirectCollectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Validation rules.
     *
     * Dynamic fields are validated by DirectCollectionService
     * because their definitions come from RevenueServiceField.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Taxpayer
            |--------------------------------------------------------------------------
            */

            'taxpayer_id' => [
                'required',
                'uuid',
                'exists:citizens,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            'revenue_service_id' => [
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Dynamic Service Fields
            |--------------------------------------------------------------------------
            |
            | The actual fields depend on the selected revenue service.
            | DirectCollectionService performs the detailed validation.
            |
            */

            'fields' => [
                'sometimes',
                'array',
            ],

            'fields.*' => [
                'nullable',
            ],

            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'nullable',
                'uuid',
            ],
        ];
    }

    /**
     * Prepare request data before validation.
     */
    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Support legacy "inputs" key
        |--------------------------------------------------------------------------
        |
        | The service supports both:
        |
        | fields
        | inputs
        |
        | We normalize inputs -> fields here so the rest of the
        | application has one canonical request structure.
        |
        */

        if (
            ! $this->has('fields')
            && $this->has('inputs')
        ) {
            $this->merge([
                'fields' => $this->input('inputs'),
            ]);
        }
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'taxpayer_id.required' =>
                'Taxpayer is required.',

            'taxpayer_id.uuid' =>
                'The selected taxpayer ID must be a valid UUID.',

            'taxpayer_id.exists' =>
                'The selected taxpayer could not be found.',

            'revenue_service_id.required' =>
                'Revenue service is required.',

            'revenue_service_id.uuid' =>
                'The revenue service ID must be a valid UUID.',

            'revenue_service_id.exists' =>
                'The selected revenue service could not be found.',

            'fields.array' =>
                'Service fields must be provided as an object or array.',

            'administrative_unit_id.uuid' =>
                'The administrative unit ID must be a valid UUID.',
        ];
    }
}