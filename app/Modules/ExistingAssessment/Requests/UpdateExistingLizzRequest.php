<?php

namespace App\Modules\ExistingAssessment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExistingLizzRequest extends FormRequest
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
                'sometimes',
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
                'sometimes',
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Dynamic Service Fields
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | {
            |     "01a0a71b-d3e5-726e-ab39-04e0d79f01f7": true,
            |     "01a0a71b-d3e1-7012-818e-df7a5d1e10df": "100"
            | }
            |
            */

            'service_fields' => [
                'sometimes',
                'required',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Source
            |--------------------------------------------------------------------------
            */

            'source' => [
                'sometimes',
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
                'sometimes',
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
                'sometimes',
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
                'sometimes',
                'required',
                'numeric',
                'min:0',

                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {

                    /*
                     * If original_obligation is included in this request,
                     * compare against the submitted value.
                     *
                     * If it is not included, the service layer must compare
                     * amount_already_paid against the existing database value.
                     */

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
     * When using multipart/form-data, service_fields is sent as
     * a JSON string.
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
    public function taxpayerId(): ?string
    {
        if (! $this->has('taxpayer_id')) {
            return null;
        }

        return $this->validated('taxpayer_id');
    }

    /**
     * ----------------------------------------------------------------------
     * REVENUE SERVICE ID
     * ----------------------------------------------------------------------
     */
    public function revenueServiceId(): ?string
    {
        if (! $this->has('revenue_service_id')) {
            return null;
        }

        return $this->validated('revenue_service_id');
    }

    /**
     * ----------------------------------------------------------------------
     * SERVICE FIELDS
     * ----------------------------------------------------------------------
     */
    public function serviceFields(): ?array
    {
        if (! $this->has('service_fields')) {
            return null;
        }

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
        if (! $this->has('source')) {
            return null;
        }

        return $this->validated('source');
    }

    /**
     * ----------------------------------------------------------------------
     * NOTES
     * ----------------------------------------------------------------------
     */
    public function notes(): ?string
    {
        if (! $this->has('notes')) {
            return null;
        }

        return $this->validated('notes');
    }

    /**
     * ----------------------------------------------------------------------
     * ORIGINAL OBLIGATION
     * ----------------------------------------------------------------------
     */
    public function originalObligation(): ?float
    {
        if (! $this->has('original_obligation')) {
            return null;
        }

        return (float) $this->validated(
            'original_obligation'
        );
    }

    /**
     * ----------------------------------------------------------------------
     * AMOUNT ALREADY PAID
     * ----------------------------------------------------------------------
     */
    public function amountAlreadyPaid(): ?float
    {
        if (! $this->has('amount_already_paid')) {
            return null;
        }

        return (float) $this->validated(
            'amount_already_paid'
        );
    }

    /**
     * ----------------------------------------------------------------------
     * HAS FINANCIAL UPDATE
     * ----------------------------------------------------------------------
     */
    public function hasFinancialUpdate(): bool
    {
        return $this->hasAny([
            'original_obligation',
            'amount_already_paid',
        ]);
    }
}
