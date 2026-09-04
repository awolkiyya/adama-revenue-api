<?php

namespace  App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevenueSettingRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Payment Period
            |--------------------------------------------------------------------------
            |
            | Ethiopian calendar:
            |
            | Months 1-12 = days 1-30
            | Month 13    = days 1-6
            |
            */

            'payment_start_month' => [
                'nullable',
                'integer',
                'between:1,13',
                'required_with:payment_start_day',
            ],

            'payment_start_day' => [
                'nullable',
                'integer',
                'between:1,31',
                'required_with:payment_start_month',
            ],

            'payment_end_month' => [
                'nullable',
                'integer',
                'between:1,13',
                'required_with:payment_end_day',
            ],

            'payment_end_day' => [
                'nullable',
                'integer',
                'between:1,31',
                'required_with:payment_end_month',
            ],


            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' => [
                'required',
                'boolean',
            ],

            'interest_enabled' => [
                'required',
                'boolean',
            ],


            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            'assessment_auto_calculation' => [
                'required',
                'boolean',
            ],

            'assessment_allow_manual_adjustment' => [
                'required',
                'boolean',
            ],

            'assessment_requires_approval' => [
                'required',
                'boolean',
            ],

            'assessment_reassessment_allowed' => [
                'required',
                'boolean',
            ],


            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_auto_numbering' => [
                'required',
                'boolean',
            ],

            'invoice_prefix' => [
                'required',
                'string',
                'max:30',
                'regex:/\S/',
            ],

            'invoice_allow_overpayment' => [
                'required',
                'boolean',
            ],

            'invoice_allow_overdue_payment' => [
                'required',
                'boolean',
            ],


            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_confirmation_required' => [
                'required',
                'boolean',
            ],

            'payment_auto_receipt' => [
                'required',
                'boolean',
            ],

            'enabled_payment_methods' => [
                'required',
                'array',
                'min:1',
            ],

            'enabled_payment_methods.*' => [
                'required',
                'string',
                Rule::in([
                    'CASH',
                    'BANK',
                    'MOBILE_MONEY',
                    'CARD',
                ]),
            ],


            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_auto_numbering' => [
                'required',
                'boolean',
            ],

            'receipt_prefix' => [
                'required',
                'string',
                'max:30',
                'regex:/\S/',
            ],

            'receipt_allow_reprint' => [
                'required',
                'boolean',
            ],


            /*
            |--------------------------------------------------------------------------
            | Documentation
            |--------------------------------------------------------------------------
            */

            'legal_reference' => [
                'nullable',
                'string',
                'max:500',
            ],

            'description' => [
                'nullable',
                'string',
            ],
        ];
    }


    /**
     * Prepare data before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('invoice_prefix')) {
            $this->merge([
                'invoice_prefix' => trim((string) $this->invoice_prefix),
            ]);
        }

        if ($this->has('receipt_prefix')) {
            $this->merge([
                'receipt_prefix' => trim((string) $this->receipt_prefix),
            ]);
        }

        if ($this->has('legal_reference')) {
            $this->merge([
                'legal_reference' => $this->legal_reference !== null
                    ? trim((string) $this->legal_reference)
                    : null,
            ]);
        }

        if ($this->has('enabled_payment_methods')) {
            $this->merge([
                'enabled_payment_methods' => array_values(
                    array_unique(
                        array_map(
                            static fn ($method) => strtoupper(trim((string) $method)),
                            $this->enabled_payment_methods ?? []
                        )
                    )
                ),
            ]);
        }
    }


    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            $this->validateEthiopianDate(
                $validator,
                'payment_start_month',
                'payment_start_day',
                'payment_start'
            );

            $this->validateEthiopianDate(
                $validator,
                'payment_end_month',
                'payment_end_day',
                'payment_end'
            );
        });
    }


    /**
     * Validate an Ethiopian month/day combination.
     */
    private function validateEthiopianDate(
        $validator,
        string $monthField,
        string $dayField,
        string $prefix
    ): void {
        $month = $this->input($monthField);
        $day = $this->input($dayField);

        /*
        |--------------------------------------------------------------------------
        | Both null = no configured period
        |--------------------------------------------------------------------------
        */

        if ($month === null && $day === null) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Month 1-12
        |--------------------------------------------------------------------------
        */

        if (
            $month !== null &&
            (int) $month >= 1 &&
            (int) $month <= 12 &&
            $day !== null &&
            (int) $day > 30
        ) {
            $validator->errors()->add(
                $dayField,
                "The {$prefix} day must be between 1 and 30 for Ethiopian months 1-12."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Month 13 / Pagume
        |--------------------------------------------------------------------------
        */

        if (
            $month !== null &&
            (int) $month === 13 &&
            $day !== null &&
            (int) $day > 6
        ) {
            $validator->errors()->add(
                $dayField,
                "The {$prefix} day must be between 1 and 6 for Ethiopian month 13."
            );
        }
    }
}