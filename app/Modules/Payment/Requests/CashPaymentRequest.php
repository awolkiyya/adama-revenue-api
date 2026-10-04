<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashPaymentRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user
     * is allowed to submit a cash payment.
     *
     * Detailed authorization should also be enforced
     * by the PaymentService / Policy layer.
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
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id' => [
                'required',
                'uuid',
                'exists:invoices,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Amount
            |--------------------------------------------------------------------------
            |
            | The PaymentService must verify that:
            |
            | - The amount is greater than zero.
            | - The invoice is payable.
            | - The amount does not exceed the outstanding balance.
            | - Partial payments are allowed for the invoice.
            |
            */

            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Description
            |--------------------------------------------------------------------------
            */

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' => [
                'nullable',
                'array',
            ],

            'metadata.*' => [
                'nullable',
            ],
        ];
    }

    /**
     * Validation messages.
     */
    public function messages(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id.required' =>
                'An invoice is required for the cash payment.',

            'invoice_id.uuid' =>
                'The invoice ID must be a valid UUID.',

            'invoice_id.exists' =>
                'The selected invoice does not exist.',

            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            */

            'amount.required' =>
                'The payment amount is required.',

            'amount.numeric' =>
                'The payment amount must be a valid number.',

            'amount.gt' =>
                'The payment amount must be greater than zero.',

            'amount.decimal' =>
                'The payment amount may have up to four decimal places.',

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description.max' =>
                'The payment description may not exceed 500 characters.',

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'metadata.array' =>
                'Payment metadata must be a valid object.',
        ];
    }

    /**
     * Normalize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'invoice_id' => $this->filled('invoice_id')
                ? trim((string) $this->input('invoice_id'))
                : null,

            'amount' => $this->filled('amount')
                ? $this->input('amount')
                : null,

            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }
}