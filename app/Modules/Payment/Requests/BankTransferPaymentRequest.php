<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BankTransferPaymentRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user
     * can submit a bank-transfer payment.
     *
     * Final authorization and business authorization
     * must also be enforced by the service/policy layer.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Validation rules.
     *
     * This request performs structural/input validation only.
     *
     * Financial and business rules such as:
     *
     * - Invoice payable status
     * - Outstanding balance
     * - Maximum payable amount
     * - Partial-payment rules
     * - Duplicate transfer reference
     * - Payment method compatibility
     * - Transfer-date business rules
     * - Payment authorization
     *
     * are enforced by BankTransferService.
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
            */

            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
            ],

            /*
            |--------------------------------------------------------------------------
            | Destination Bank Account
            |--------------------------------------------------------------------------
            |
            | Identifies the municipal bank account that received
            | the transfer.
            |
            */

            'bank_account_id' => [
                'required',
                'uuid',
                'exists:bank_accounts,id',
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
            |
            | Information about the bank account/person that actually
            | sent the transfer.
            |
            */

            'sender_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'sender_account' => [
                'nullable',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payer Information
            |--------------------------------------------------------------------------
            |
            | The payer can be different from the invoice taxpayer
            | or the bank-transfer sender.
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
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Supporting Evidence
            |--------------------------------------------------------------------------
            |
            | Examples:
            |
            | - Bank transfer receipt
            | - Deposit slip
            | - Bank statement
            | - Transfer confirmation
            |
            | The request only validates the uploaded file.
            |
            | BankTransferService is responsible for:
            |
            | 1. Uploading the file through StorageService.
            | 2. Creating the File registry record.
            | 3. Attaching the File to BankTransferDetail.
            |
            | Evidence is optional at submission level.
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
            |
            | Auxiliary application metadata only.
            |
            | System-controlled financial fields must never be
            | trusted from this array.
            |
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
            | Destination Bank Account
            |--------------------------------------------------------------------------
            */

            'bank_account_id.required' =>
                'A municipal bank account is required.',

            'bank_account_id.uuid' =>
                'The bank account ID must be a valid UUID.',

            'bank_account_id.exists' =>
                'The selected municipal bank account does not exist.',

            /*
            |--------------------------------------------------------------------------
            | Transfer Information
            |--------------------------------------------------------------------------
            */

            'transfer_reference.required' =>
                'The bank transfer reference is required.',

            'transfer_reference.string' =>
                'The bank transfer reference must be a valid text value.',

            'transfer_reference.max' =>
                'The bank transfer reference may not exceed 150 characters.',

            'transfer_date.required' =>
                'The transfer date is required.',

            'transfer_date.date' =>
                'The transfer date must be a valid date.',

            /*
            |--------------------------------------------------------------------------
            | Sender Information
            |--------------------------------------------------------------------------
            */

            'sender_name.string' =>
                'The sender name must be a valid text value.',

            'sender_name.max' =>
                'The sender name may not exceed 255 characters.',

            'sender_account.string' =>
                'The sender account must be a valid text value.',

            'sender_account.max' =>
                'The sender account may not exceed 100 characters.',

            /*
            |--------------------------------------------------------------------------
            | Payer Information
            |--------------------------------------------------------------------------
            */

            'payer_name.string' =>
                'The payer name must be a valid text value.',

            'payer_name.max' =>
                'The payer name may not exceed 255 characters.',

            'payer_phone.string' =>
                'The payer phone number must be a valid text value.',

            'payer_phone.max' =>
                'The payer phone number may not exceed 30 characters.',

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes.string' =>
                'The notes must be a valid text value.',

            'notes.max' =>
                'The notes may not exceed 1,000 characters.',

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
     * Normalize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id' => $this->filled('invoice_id')
                ? trim((string) $this->input('invoice_id'))
                : null,

            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            |
            | Keep the original numeric representation so Laravel's
            | numeric/decimal validation can process it correctly.
            |
            */

            'amount' => $this->filled('amount')
                ? $this->input('amount')
                : null,

            /*
            |--------------------------------------------------------------------------
            | Bank Account
            |--------------------------------------------------------------------------
            */

            'bank_account_id' => $this->filled('bank_account_id')
                ? trim((string) $this->input('bank_account_id'))
                : null,

            /*
            |--------------------------------------------------------------------------
            | Transfer Information
            |--------------------------------------------------------------------------
            */

            'transfer_reference' => $this->filled('transfer_reference')
                ? trim((string) $this->input('transfer_reference'))
                : null,

            'transfer_date' => $this->filled('transfer_date')
                ? trim((string) $this->input('transfer_date'))
                : null,

            /*
            |--------------------------------------------------------------------------
            | Sender Information
            |--------------------------------------------------------------------------
            */

            'sender_name' => $this->filled('sender_name')
                ? trim((string) $this->input('sender_name'))
                : null,

            'sender_account' => $this->filled('sender_account')
                ? trim((string) $this->input('sender_account'))
                : null,

            /*
            |--------------------------------------------------------------------------
            | Payer Information
            |--------------------------------------------------------------------------
            */

            'payer_name' => $this->filled('payer_name')
                ? trim((string) $this->input('payer_name'))
                : null,

            'payer_phone' => $this->filled('payer_phone')
                ? trim((string) $this->input('payer_phone'))
                : null,

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => $this->filled('notes')
                ? trim((string) $this->input('notes'))
                : null,
        ]);
    }
}
