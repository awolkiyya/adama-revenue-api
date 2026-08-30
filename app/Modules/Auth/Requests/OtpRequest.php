<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Supports\PhoneNumber;


class OtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }


    /**
     * OTP request validation rules.
     */
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                'regex:/^\+251(7|9)[0-9]{8}$/',
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

            'type.required' => 'OTP type is required.',
            'type.in' => 'Invalid OTP type selected.',
        ];
    }


    /**
 * Normalize Ethiopian phone number format.
 *
 * Accepts:
 * 0911234567     => +251911234567
 * 0711234567     => +251711234567
 * 911234567      => +251911234567
 * 711234567      => +251711234567
 * 251911234567   => +251911234567
 * +251911234567  => +251911234567
 */

protected function prepareForValidation(): void
{
    if ($this->filled('phone')) {
        $this->merge([
            'phone' => PhoneNumber::normalizeEthiopian($this->phone),
            'type'  => $this->filled('type') ? strtolower(trim($this->type)) : $this->type,
        ]);
    }
}
}