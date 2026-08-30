<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user is authorized
     * to request payment verification.
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
            | Payment
            |--------------------------------------------------------------------------
            |
            | The payment UUID identifies the local payment record.
            |
            */

            'payment_id' => [
                'required',
                'string',
                'uuid',
                'exists:payments,uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Transaction Reference
            |--------------------------------------------------------------------------
            |
            | Optional because the provider reference should normally
            | come from the existing payment record.
            |
            | It can be supplied when the provider returns a reference
            | that needs to be matched against the local transaction.
            |
            */

            'transaction_reference' => [
                'nullable',
                'string',
                'max:150',
            ],
        ];
    }

    /**
     * Validation messages.
     */
    public function messages(): array
    {
        return [
            'payment_id.required' =>
                'A payment ID is required.',

            'payment_id.uuid' =>
                'The payment ID must be a valid UUID.',

            'payment_id.exists' =>
                'The selected payment does not exist.',

            'transaction_reference.max' =>
                'The transaction reference may not exceed 150 characters.',
        ];
    }

    /**
     * Normalize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'payment_id' => $this->filled('payment_id')
                ? trim((string) $this->input('payment_id'))
                : null,

            'transaction_reference' => $this->filled('transaction_reference')
                ? trim((string) $this->input('transaction_reference'))
                : null,
        ]);
    }
}