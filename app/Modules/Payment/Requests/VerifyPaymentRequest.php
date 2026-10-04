<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user
     * can request payment verification.
     *
     * Final authorization must also be enforced
     * by the Policy / Service layer.
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
            'payment_id' => [
                'required',
                'uuid',
                'exists:payments,id',
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
        ]);
    }
}