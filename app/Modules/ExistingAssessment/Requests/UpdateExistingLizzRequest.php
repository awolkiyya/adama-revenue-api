<?php

namespace App\Modules\ExistingAssessment\Requests;

use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
                    Closure $fail
                ): void {

                    /*
                     * When original_obligation is included in this request,
                     * validate against the submitted value.
                     *
                     * If it is not included, the existing database value
                     * must be validated by the service/domain layer.
                     */
                    $originalObligation = $this->input(
                        'original_obligation'
                    );

                    if ($originalObligation === null) {
                        return;
                    }

                    if (
                        is_numeric($value) &&
                        is_numeric($originalObligation) &&
                        (float) $value > (float) $originalObligation
                    ) {
                        $fail(
                            'The amount already paid cannot be greater than the original historical obligation.'
                        );
                    }
                },
            ],

            /*
            |--------------------------------------------------------------------------
            | Balance As Of Date
            |--------------------------------------------------------------------------
            |
            | Historical date on which the outstanding balance was determined.
            |
            | Optional because this endpoint supports partial updates.
            |
            */

            'balance_as_of_date' => [
                'sometimes',
                'required',
                'date',
            ],
        ];
    }

    /**
     * ----------------------------------------------------------------------
     * AFTER VALIDATION
     * ----------------------------------------------------------------------
     *
     * Validate cross-field business rules.
     *
     * Important:
     *
     *     agreement_date <= balance_as_of_date
     *
     * Because this is a PATCH-style request, one of the values may not
     * be present in the request. In that case, the existing database
     * value must be considered by the service/domain layer.
     *
     * This request validates the relationship when both values are
     * available in the current request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {

                /*
                 * Only perform the request-level comparison when both
                 * values are available.
                 */
                $agreementDate = $this->agreementDate();

                $balanceAsOfDate = $this->input(
                    'balance_as_of_date'
                );

                if (
                    $agreementDate === null ||
                    empty($balanceAsOfDate)
                ) {
                    return;
                }

                try {
                    $balanceDate = Carbon::parse(
                        $balanceAsOfDate
                    );
                } catch (\Throwable) {
                    return;
                }

                /*
                 |--------------------------------------------------------------------------
                 | LIZZ DATE INVARIANT
                 |--------------------------------------------------------------------------
                 |
                 | The historical balance cannot be established before
                 | the agreement existed.
                 |
                 | Valid:
                 |
                 |     agreement_date     = 2009-09-11
                 |     balance_as_of_date = 2025-09-18
                 |
                 | Also valid:
                 |
                 |     agreement_date     = 2009-09-11
                 |     balance_as_of_date = 2009-09-11
                 |
                 | Invalid:
                 |
                 |     agreement_date     = 2009-09-11
                 |     balance_as_of_date = 1997-09-18
                 |
                 */

                if ($balanceDate->lt($agreementDate)) {
                    $validator->errors()->add(
                        'balance_as_of_date',
                        sprintf(
                            'The balance as of date must be on or after the LIZZ agreement date. Agreement date: %s. Balance as of date: %s.',
                            $agreementDate->toDateString(),
                            $balanceDate->toDateString(),
                        )
                    );
                }
            },
        ];
    }

    /**
     * ----------------------------------------------------------------------
     * PREPARE FOR VALIDATION
     * ----------------------------------------------------------------------
     *
     * When using multipart/form-data, service_fields may arrive as
     * a JSON string.
     */
    protected function prepareForValidation(): void
    {
        $serviceFields = $this->input('service_fields');

        if (! is_string($serviceFields)) {
            return;
        }

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
     * BALANCE AS OF DATE
     * ----------------------------------------------------------------------
     */
    public function balanceAsOfDate(): ?string
    {
        if (! $this->has('balance_as_of_date')) {
            return null;
        }

        return $this->validated(
            'balance_as_of_date'
        );
    }

    /**
     * ----------------------------------------------------------------------
     * AGREEMENT DATE
     * ----------------------------------------------------------------------
     *
     * Agreement date is stored inside dynamic service fields.
     *
     * Expected field:
     *
     *     AGREEMENT_DATE
     */
    public function agreementDate(): ?Carbon
    {
        $value = data_get(
            $this->input('service_fields', []),
            'AGREEMENT_DATE'
        );

        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
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
            'balance_as_of_date',
        ]);
    }
}