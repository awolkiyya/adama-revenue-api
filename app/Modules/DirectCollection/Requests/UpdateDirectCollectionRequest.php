<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDirectCollectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Dynamic service-field validation is handled by
     * DirectCollectionInputService because the required fields
     * depend on the selected revenue service.
     *
     * Financial values are intentionally NOT accepted from the client.
     *
     * The server calculates:
     *
     * - amount
     * - currency
     * - quantity
     * - unit
     * - unit_price
     * - tariff version
     * - tariff rule
     * - penalty rule
     * - interest rule
     * - due date
     * - calculation snapshot
     * - input snapshot
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Taxpayer
            |--------------------------------------------------------------------------
            |
            | Either taxpayer_id or citizen_id may be supplied.
            |
            | DirectCollectionInputService resolves the final taxpayer.
            |
            */

            'taxpayer_id' => [
                'nullable',
                'uuid',
                'exists:citizens,id',
            ],

            'citizen_id' => [
                'nullable',
                'uuid',
                'exists:citizens,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            |
            | Either revenue_service_id or service_id may be supplied.
            |
            */

            'revenue_service_id' => [
                'nullable',
                'uuid',
                'exists:revenue_services,id',
            ],

            'service_id' => [
                'nullable',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Dynamic Service Fields
            |--------------------------------------------------------------------------
            |
            | The actual field-level validation is performed by
            | DirectCollectionInputService.
            |
            */

            'fields' => [
                'nullable',
                'array',
            ],

            'inputs' => [
                'nullable',
                'array',
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

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Edit Reason
            |--------------------------------------------------------------------------
            |
            | Recommended for auditability.
            |
            */

            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Financial Fields
            |--------------------------------------------------------------------------
            |
            | DO NOT add these fields here.
            |
            | They must always be calculated by the backend.
            |
            */
        ];
    }

    /**
     * Normalize alternative input names before validation.
     *
     * The frontend may send `inputs` instead of `fields`.
     *
     * Internally, DirectCollectionInputService uses `fields`.
     */
    protected function prepareForValidation(): void
    {
        if (
            ! $this->has('fields')
            && $this->has('inputs')
        ) {
            $this->merge([
                'fields' => $this->input('inputs'),
            ]);
        }
    }
}
