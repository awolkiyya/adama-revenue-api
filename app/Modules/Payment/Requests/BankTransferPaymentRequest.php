<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BankTransferPaymentRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user
     * can submit a bank-transfer payment.
     *
     * Final authorization must also be enforced
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
            | The PaymentService must additionally verify:
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
            | Bank Information
            |--------------------------------------------------------------------------
            */

            'bank_name' => [
                'required',
                'string',
                'max:150',
            ],

            'bank_account_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'bank_account_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Transfer Information
            |--------------------------------------------------------------------------
            */

            'transfer_reference' => [
                'required',
                'string',
                'max:150',
            ],

            'transfer_date' => [
                'required',
                'date',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payer Information
            |--------------------------------------------------------------------------
            |
            | The payer may be:
            |
            | - The taxpayer themselves.
            | - An authorized agent.
            | - Another person paying on behalf of the taxpayer.
            | - A company paying on behalf of the taxpayer.
            |
            | This is intentionally separate from the invoice taxpayer
            | and from the authenticated municipal employee.
            |
            */

            'payer_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'payer_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            /*
            |--------------------------------------------------------------------------
            | Supporting Evidence
            |--------------------------------------------------------------------------
            |
            | Examples:
            | - Bank transfer receipt
            | - Deposit slip
            | - Bank statement
            | - Transfer confirmation
            |
            | The actual file should be stored through the application's
            | private document/file service.
            |
            */

            'evidence' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
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
                'An invoice is required for the bank transfer.',

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
            | Bank Information
            |--------------------------------------------------------------------------
            */

            'bank_name.required' =>
                'The bank name is required.',

            'bank_name.max' =>
                'The bank name may not exceed 150 characters.',

            'bank_account_name.max' =>
                'The bank account name may not exceed 255 characters.',

            'bank_account_number.max' =>
                'The bank account number may not exceed 100 characters.',

            /*
            |--------------------------------------------------------------------------
            | Transfer Information
            |--------------------------------------------------------------------------
            */

            'transfer_reference.required' =>
                'The bank transfer reference is required.',

            'transfer_reference.max' =>
                'The bank transfer reference may not exceed 150 characters.',

            'transfer_date.required' =>
                'The transfer date is required.',

            'transfer_date.date' =>
                'The transfer date must be a valid date.',

            /*
            |--------------------------------------------------------------------------
            | Payer Information
            |--------------------------------------------------------------------------
            */

            'payer_name.max' =>
                'The payer name may not exceed 255 characters.',

            'payer_phone.max' =>
                'The payer phone number may not exceed 30 characters.',

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            'description.max' =>
                'The payment description may not exceed 500 characters.',

            /*
            |--------------------------------------------------------------------------
            | Evidence
            |--------------------------------------------------------------------------
            */

            'evidence.file' =>
                'The payment evidence must be a valid file.',

            'evidence.mimes' =>
                'Payment evidence must be a JPG, JPEG, PNG, or PDF file.',

            'evidence.max' =>
                'Payment evidence may not be larger than 5 MB.',

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
     * Normalize textual input before validation.
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

            'bank_name' => $this->filled('bank_name')
                ? trim((string) $this->input('bank_name'))
                : null,

            'bank_account_name' => $this->filled('bank_account_name')
                ? trim((string) $this->input('bank_account_name'))
                : null,

            'bank_account_number' => $this->filled('bank_account_number')
                ? trim((string) $this->input('bank_account_number'))
                : null,

            'transfer_reference' => $this->filled('transfer_reference')
                ? trim((string) $this->input('transfer_reference'))
                : null,

            'payer_name' => $this->filled('payer_name')
                ? trim((string) $this->input('payer_name'))
                : null,

            'payer_phone' => $this->filled('payer_phone')
                ? trim((string) $this->input('payer_phone'))
                : null,

            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }
}