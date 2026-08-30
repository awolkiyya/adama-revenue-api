<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }


    /**
     * Validation rules for OTP verification.
     */
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                'regex:/^\+251(7|9)[0-9]{8}$/',
            ],

            'otp' => [
                'required',
                'string',
                'digits:6',
            ],

            'type' => [
                'required',
                'string',
                'in:login,registration,verification',
            ],
        ];
    }


    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please provide a valid Ethiopian mobile number.',

            'otp.required' => 'OTP code is required.',
            'otp.digits' => 'OTP code must be exactly 6 digits.',

            'type.required' => 'OTP type is required.',
            'type.in' => 'Invalid OTP type selected.',
        ];
    }


    /**
     * Normalize input before validation.
     *
     * Examples:
     * 0911234567  => +251911234567
     * 0711234567  => +251711234567
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {

            $phone = preg_replace('/[\s\-\(\)]/', '', $this->phone);

            if (str_starts_with($phone, '0')) {
                $phone = '+251' . substr($phone, 1);
            }

            $this->merge([
                'phone' => $phone,
                'otp' => trim($this->otp),
                'type' => strtolower(trim($this->type)),
            ]);
        }
    }
}