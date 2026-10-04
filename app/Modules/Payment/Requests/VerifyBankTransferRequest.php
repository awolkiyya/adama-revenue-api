<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyBankTransferRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user
     * may request bank-transfer verification.
     *
     * Final authorization must also be enforced
     * by Policy / Service / Permission middleware.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Validation rules.
     *
     * The payment ID is intentionally NOT here because
     * it comes from the route:
     *
     *     /bank-transfers/{payment}/verify
     */
    public function rules(): array
    {
        return [
            'verification_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * Validation messages.
     */
    public function messages(): array
    {
        return [
            'verification_notes.string' =>
                'Verification notes must be a valid string.',

            'verification_notes.max' =>
                'Verification notes may not exceed 1000 characters.',
        ];
    }

    /**
     * Normalize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'verification_notes' =>
                $this->filled('verification_notes')
                    ? trim(
                        (string) $this->input(
                            'verification_notes'
                        )
                    )
                    : null,
        ]);
    }
}