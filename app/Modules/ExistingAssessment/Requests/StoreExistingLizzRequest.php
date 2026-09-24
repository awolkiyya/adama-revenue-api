<?php

namespace App\Modules\ExistingAssessment\Requests;

use App\Models\RevenueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExistingLizzRequest extends FormRequest
{
    /**
     * ----------------------------------------------------------------------
     * AUTHORIZE
     * ----------------------------------------------------------------------
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ----------------------------------------------------------------------
     * VALIDATION RULES
     * ----------------------------------------------------------------------
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
            |
            | Existing LIZZ uses exactly ONE revenue service.
            |
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
            | Frontend sends this as a JSON string when using multipart/form-data.
            |
            | Example:
            |
            | {
            |     "field-uuid-1": true,
            |     "field-uuid-2": "100",
            |     "field-uuid-3": "2026-09-11"
            | }
            |
            */

            'service_fields' => [
                'required',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Source
            |--------------------------------------------------------------------------
            */

            'source' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Original Historical Obligation
            |--------------------------------------------------------------------------
            */

            'original_obligation' => [
                'required',
                'numeric',
                'min:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Historical Amount Already Paid
            |--------------------------------------------------------------------------
            */

            'amount_already_paid' => [
                'required',
                'numeric',
                'min:0',

                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {

                    $originalObligation = $this->input(
                        'original_obligation'
                    );

                    if ($originalObligation === null) {
                        return;
                    }

                    if (
                        (float) $value >
                        (float) $originalObligation
                    ) {
                        $fail(
                            'amount_already_paid cannot be greater than original_obligation.'
                        );
                    }
                },
            ],
        ];
    }

    /**
     * ----------------------------------------------------------------------
     * PREPARE FOR VALIDATION
     * ----------------------------------------------------------------------
     *
     * When the frontend uses multipart/form-data, service_fields arrives
     * as a JSON string.
     *
     * Convert:
     *
     *     '{"field-id":"value"}'
     *
     * into:
     *
     *     ['field-id' => 'value']
     */
    protected function prepareForValidation(): void
    {
        $serviceFields = $this->input('service_fields');

        if (is_string($serviceFields)) {

            $decoded = json_decode(
                $serviceFields,
                true
            );

            if (
                json_last_error() === JSON_ERROR_NONE &&
                is_array($decoded)
            ) {
                $this->merge([
                    'service_fields' => $decoded,
                ]);
            }
        }
    }

    /**
     * ----------------------------------------------------------------------
     * TAXPAYER ID
     * ----------------------------------------------------------------------
     */
    public function taxpayerId(): string
    {
        return $this->validated('taxpayer_id');
    }

    /**
     * ----------------------------------------------------------------------
     * REVENUE SERVICE ID
     * ----------------------------------------------------------------------
     */
    public function revenueServiceId(): string
    {
        return $this->validated('revenue_service_id');
    }

    /**
     * ----------------------------------------------------------------------
     * SERVICE FIELDS
     * ----------------------------------------------------------------------
     */
    public function serviceFields(): array
    {
        return $this->validated(
            'service_fields',
            []
        );
    }

    /**
     * ----------------------------------------------------------------------
     * SOURCE
     * ----------------------------------------------------------------------
     */
    public function source(): ?string
    {
        return $this->validated('source');
    }

    /**
     * ----------------------------------------------------------------------
     * NOTES
     * ----------------------------------------------------------------------
     */
    public function notes(): ?string
    {
        return $this->validated('notes');
    }

    /**
     * ----------------------------------------------------------------------
     * ORIGINAL OBLIGATION
     * ----------------------------------------------------------------------
     */
    public function originalObligation(): float
    {
        return (float) $this->validated(
            'original_obligation'
        );
    }

    /**
     * ----------------------------------------------------------------------
     * AMOUNT ALREADY PAID
     * ----------------------------------------------------------------------
     */
    public function amountAlreadyPaid(): float
    {
        return (float) $this->validated(
            'amount_already_paid'
        );
    }

    /**
     * ----------------------------------------------------------------------
     * OUTSTANDING BALANCE
     * ----------------------------------------------------------------------
     *
     * This is derived by the backend.
     *
     * It is NOT accepted from the frontend.
     */
    public function outstandingBalance(): float
    {
        return max(
            0,
            $this->originalObligation()
                - $this->amountAlreadyPaid()
        );
    }
}