<?php

namespace App\Modules\Invoice\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceIndexRequest extends FormRequest
{
    /*
    |--------------------------------------------------------------------------
    | AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    public function authorize(): bool
    {
        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | PREPARE INPUT
    |--------------------------------------------------------------------------
    */

    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | NORMALIZE STATUS
        |--------------------------------------------------------------------------
        |
        | Supports both:
        |
        | ?status=PAID
        |
        | ?status[]=PAID&status[]=ISSUED
        |
        */

        if ($this->has('status') && is_string($this->status)) {

            $this->merge([
                'status' => [$this->status],
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | NORMALIZE PAGINATION
        |--------------------------------------------------------------------------
        */

        if ($this->has('page')) {

            $this->merge([
                'page' => (int) $this->page,
            ]);
        }


        if ($this->has('per_page')) {

            $this->merge([
                'per_page' => (int) $this->per_page,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | NORMALIZE SEARCH
        |--------------------------------------------------------------------------
        */

        if ($this->has('search')) {

            $this->merge([
                'search' => trim(
                    (string) $this->search
                ),
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RULES
    |--------------------------------------------------------------------------
    */

    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | SEARCH
            |--------------------------------------------------------------------------
            |
            | Searches across:
            |
            | - invoice number
            | - citizen number
            | - citizen name
            | - assessment number
            |
            */

            'search' => [
                'nullable',
                'string',
                'max:100',
            ],


            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            'status' => [
                'nullable',
                'array',
            ],

            'status.*' => [
                'string',
                Rule::in([
                    'DRAFT',
                    'ISSUED',
                    'PARTIALLY_PAID',
                    'PAID',
                    'OVERDUE',
                    'CANCELLED',
                    'VOID',
                ]),
            ],


            /*
            |--------------------------------------------------------------------------
            | FISCAL YEAR
            |--------------------------------------------------------------------------
            |
            | Nullable because fiscal_year is currently nullable
            | in the invoices table.
            |
            */

            'fiscal_year' => [
                'nullable',
                'integer',
                'min:2000',
                'max:2200',
            ],


            /*
            |--------------------------------------------------------------------------
            | SOURCE TYPE
            |--------------------------------------------------------------------------
            */

            'source_type' => [
                'nullable',
                Rule::in([
                    'ASSESSMENT',
                    'DIRECT_COLLECTION',
                ]),
            ],


            /*
            |--------------------------------------------------------------------------
            | DUE DATE RANGE
            |--------------------------------------------------------------------------
            */

            'due_date_from' => [
                'nullable',
                'date',
            ],

            'due_date_to' => [
                'nullable',
                'date',
                'after_or_equal:due_date_from',
            ],


            /*
            |--------------------------------------------------------------------------
            | ISSUED DATE RANGE
            |--------------------------------------------------------------------------
            */

            'issued_from' => [
                'nullable',
                'date',
            ],

            'issued_to' => [
                'nullable',
                'date',
                'after_or_equal:issued_from',
            ],


            /*
            |--------------------------------------------------------------------------
            | PAGINATION
            |--------------------------------------------------------------------------
            */

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | CUSTOM MESSAGES
    |--------------------------------------------------------------------------
    */

    public function messages(): array
    {
        return [

            'status.array' =>
                'The status filter must be an array.',

            'status.*.in' =>
                'The selected invoice status is invalid.',

            'source_type.in' =>
                'The selected invoice source type is invalid.',

            'fiscal_year.integer' =>
                'The fiscal year must be a valid number.',

            'fiscal_year.min' =>
                'The fiscal year is invalid.',

            'fiscal_year.max' =>
                'The fiscal year is invalid.',

            'due_date_to.after_or_equal' =>
                'The due date end must be on or after the start date.',

            'issued_to.after_or_equal' =>
                'The issued date end must be on or after the start date.',

            'per_page.min' =>
                'The minimum number of invoices per page is 1.',

            'per_page.max' =>
                'The maximum number of invoices per page is 100.',
        ];
    }
}