<?php

namespace App\Modules\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Validate the new password.
     */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                Password::min(12)
                    ->letters()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'password.required' =>
                'New password is required.',

            'password.string' =>
                'Password must be a valid string.',
        ];
    }

    /**
     * Custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'password' => 'new password',
        ];
    }
}
