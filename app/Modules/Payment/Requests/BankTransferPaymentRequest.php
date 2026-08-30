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
            | Sender Information
            |--------------------------------------------------------------------------
            */

            'sender_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'sender_phone' => [
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
            | The actual file upload can be handled by a dedicated
            | document/private-file service. We only validate the
            | uploaded file here.
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
            'invoice_id.required' =>
                'An invoice is required for the bank transfer.',

            'invoice_id.integer' =>
                'The invoice ID must be a valid integer.',

            'invoice_id.exists' =>
                'The selected invoice does not exist.',

            'bank_name.required' =>
                'The bank name is required.',

            'bank_name.max' =>
                'The bank name may not exceed 150 characters.',

            'bank_account_name.max' =>
                'The bank account name may not exceed 255 characters.',

            'bank_account_number.max' =>
                'The bank account number may not exceed 100 characters.',

            'transfer_reference.required' =>
                'The bank transfer reference is required.',

            'transfer_reference.max' =>
                'The bank transfer reference may not exceed 150 characters.',

            'transfer_date.required' =>
                'The transfer date is required.',

            'transfer_date.date' =>
                'The transfer date must be a valid date.',

            'sender_name.max' =>
                'The sender name may not exceed 255 characters.',

            'sender_phone.max' =>
                'The sender phone number may not exceed 30 characters.',

            'description.max' =>
                'The payment description may not exceed 500 characters.',

            'evidence.file' =>
                'The payment evidence must be a valid file.',

            'evidence.mimes' =>
                'Payment evidence must be a JPG, JPEG, PNG, or PDF file.',

            'evidence.max' =>
                'Payment evidence may not be larger than 5 MB.',

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

            'sender_name' => $this->filled('sender_name')
                ? trim((string) $this->input('sender_name'))
                : null,

            'sender_phone' => $this->filled('sender_phone')
                ? trim((string) $this->input('sender_phone'))
                : null,

            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }
}