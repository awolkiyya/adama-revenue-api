<?php

namespace App\Modules\ExistingAssessment\Requests;

use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            |
            | Original amount of the historical LIZZ obligation.
            |
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
            |
            | Amount that was paid before the LIZZ balance was registered
            | in this system.
            |
            */

            'amount_already_paid' => [
                'required',
                'numeric',
                'min:0',

                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail
                ): void {
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
            | The historical cutoff date on which the outstanding LIZZ
            | balance was determined.
            |
            | Business invariant:
            |
            |     balance_as_of_date >= agreement_date
            |
            */

            'balance_as_of_date' => [
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
     * Validate relationships between fields that cannot be expressed
     * cleanly using independent validation rules.
     *
     * Current rule:
     *
     *     agreement_date <= balance_as_of_date
     *
     * The agreement date is stored inside dynamic service fields.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {

                $agreementDate = $this->agreementDate();

                $balanceAsOfDate = $this->input(
                    'balance_as_of_date'
                );

                /*
                 * The individual fields are already validated by rules().
                 * If either value is missing, malformed, or unavailable,
                 * allow the normal validation errors to handle it.
                 */
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
     * service_fields may arrive as JSON when the request is submitted
     * through multipart/form-data.
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
     * BALANCE AS OF DATE
     * ----------------------------------------------------------------------
     */
    public function balanceAsOfDate(): string
    {
        return $this->validated(
            'balance_as_of_date'
        );
    }

    /**
     * ----------------------------------------------------------------------
     * AGREEMENT DATE
     * ----------------------------------------------------------------------
     *
     * Agreement date is part of the dynamic service fields.
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
     * OUTSTANDING BALANCE
     * ----------------------------------------------------------------------
     *
     * Derived entirely by the backend.
     *
     * The frontend must NOT submit this value.
     *
     * Formula:
     *
     *     original_obligation - amount_already_paid
     *
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