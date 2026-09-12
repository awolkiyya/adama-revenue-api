<?php

namespace App\Modules\Revenue\Requests;

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
            | Annual Payment Due Date
            |--------------------------------------------------------------------------
            |
            | Ethiopian recurring annual date.
            |
            | Format:
            |
            |     MM-DD
            |
            | Months 1-12 = days 1-30
            | Month 13    = days 1-6 (Pagume)
            |
            | Examples:
            |
            |     01-01
            |     10-30
            |     13-06
            |
            | The year is intentionally not stored because the date
            | recurs every Ethiopian calendar year.
            |
            */

            'annual_payment_due_date' => [
                'nullable',
                'string',
                'regex:/^(0[1-9]|1[0-3])-(0[1-9]|[12][0-9]|30)$/',
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
        if ($this->has('annual_payment_due_date')) {
            $this->merge([
                'annual_payment_due_date' => $this->annual_payment_due_date !== null
                    ? trim((string) $this->annual_payment_due_date)
                    : null,
            ]);
        }

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

        if ($this->has('description')) {
            $this->merge([
                'description' => $this->description !== null
                    ? trim((string) $this->description)
                    : null,
            ]);
        }

        if ($this->has('enabled_payment_methods')) {
            $this->merge([
                'enabled_payment_methods' => array_values(
                    array_unique(
                        array_map(
                            static fn ($method) => strtoupper(
                                trim((string) $method)
                            ),
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

            $this->validateAnnualPaymentDueDate($validator);
        });
    }


    /**
     * Validate the Ethiopian recurring annual payment due date.
     *
     * Expected format:
     *
     *     MM-DD
     *
     * Months 1-12:
     *     days 1-30
     *
     * Month 13 / Pagume:
     *     days 1-6
     */
    private function validateAnnualPaymentDueDate($validator): void
    {
        $value = $this->input('annual_payment_due_date');

        /*
        |--------------------------------------------------------------------------
        | Null = no configured annual payment due date
        |--------------------------------------------------------------------------
        */

        if ($value === null || $value === '') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Parse MM-DD
        |--------------------------------------------------------------------------
        */

        if (! preg_match('/^(\d{2})-(\d{2})$/', $value, $matches)) {
            return;
        }

        $month = (int) $matches[1];
        $day = (int) $matches[2];


        /*
        |--------------------------------------------------------------------------
        | Ethiopian months 1-12
        |--------------------------------------------------------------------------
        */

        if ($month >= 1 && $month <= 12) {
            if ($day < 1 || $day > 30) {
                $validator->errors()->add(
                    'annual_payment_due_date',
                    'The annual payment due date must use a day between 1 and 30 for Ethiopian months 1-12.'
                );
            }

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Ethiopian month 13 / Pagume
        |--------------------------------------------------------------------------
        */

        if ($month === 13) {
            if ($day < 1 || $day > 6) {
                $validator->errors()->add(
                    'annual_payment_due_date',
                    'The annual payment due date must use a day between 1 and 6 for Ethiopian month 13 (Pagume).'
                );
            }
        }
    }
}
