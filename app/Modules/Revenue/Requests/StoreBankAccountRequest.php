<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => [
                'required',
                'string',
                'max:255',
            ],

            'account_name' => [
                'required',
                'string',
                'max:255',
            ],

            'account_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('bank_accounts', 'account_number'),
            ],

            'currency' => [
                'sometimes',
                'string',
                'size:3',
                'uppercase',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_name.required' => 'Bank name is required.',
            'bank_name.max' => 'Bank name may not exceed 255 characters.',

            'account_name.required' => 'Account name is required.',
            'account_name.max' => 'Account name may not exceed 255 characters.',

            'account_number.required' => 'Account number is required.',
            'account_number.max' => 'Account number may not exceed 100 characters.',
            'account_number.unique' => 'This account number is already registered.',

            'currency.size' => 'Currency must be exactly 3 characters.',
            'currency.uppercase' => 'Currency must use uppercase letters.',

            'is_active.boolean' => 'Active status must be true or false.',
        ];
    }
}