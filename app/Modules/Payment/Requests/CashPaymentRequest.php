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
     * by the PaymentService / policy layer.
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
                'integer',
                'exists:invoices,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Cash Collection Information
            |--------------------------------------------------------------------------
            */

            'received_by' => [
                'nullable',
                'string',
                'max:255',
            ],

            'receipt_reference' => [
                'nullable',
                'string',
                'max:100',
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
            'invoice_id.required' =>
                'An invoice is required for the cash payment.',

            'invoice_id.integer' =>
                'The invoice ID must be a valid integer.',

            'invoice_id.exists' =>
                'The selected invoice does not exist.',

            'received_by.max' =>
                'The receiver name may not exceed 255 characters.',

            'receipt_reference.max' =>
                'The receipt reference may not exceed 100 characters.',

            'description.max' =>
                'The payment description may not exceed 500 characters.',

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
            'received_by' => $this->filled('received_by')
                ? trim((string) $this->input('received_by'))
                : null,

            'receipt_reference' => $this->filled('receipt_reference')
                ? trim((string) $this->input('receipt_reference'))
                : null,

            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }
}