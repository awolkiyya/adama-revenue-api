<?php

namespace App\Modules\Citizens\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCitizenRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data before validation.
     */
    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Phone Normalization
        |--------------------------------------------------------------------------
        */

        if ($this->filled('phone')) {

            $phone = preg_replace(
                '/\s+/',
                '',
                $this->phone
            );

            /*
             * Convert Ethiopian local format:
             *
             * 0911123456
             *
             * to:
             *
             * +251911123456
             */
            if (str_starts_with($phone, '0')) {

                $phone =
                    '+251' .
                    substr($phone, 1);
            }

            $this->merge([
                'phone' => $phone,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Gender Normalization
        |--------------------------------------------------------------------------
        */

        if ($this->filled('gender')) {

            $this->merge([
                'gender' => strtoupper(
                    $this->gender
                ),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Email Normalization
        |--------------------------------------------------------------------------
        |
        | Email is NOT unique.
        |
        | The same email address can belong to multiple citizens.
        |
        */

        if ($this->filled('email')) {

            $this->merge([
                'email' => strtolower(
                    trim($this->email)
                ),
            ]);
        }
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'full_name' => [
                'required',
                'string',
                'min:3',
                'max:150',
            ],

            'national_id' => [
                'required',
                'string',
                'max:100',
                Rule::unique(
                    'citizens',
                    'national_id'
                ),
            ],

            'gender' => [
                'required',
                Rule::in([
                    'MALE',
                    'FEMALE',
                    'OTHER',
                ]),
            ],

            'date_of_birth' => [
                'nullable',
                'date',
                'before:today',
            ],

            /*
            |--------------------------------------------------------------------------
            | Contact
            |--------------------------------------------------------------------------
            */

            'phone' => [
                'required',
                'regex:/^\+251(7|9)[0-9]{8}$/',
                Rule::unique(
                    'citizens',
                    'phone'
                ),
            ],

            /*
             * Email is optional and NOT unique.
             *
             * Multiple citizens may use the same email address.
             */
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Address
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'required',
                'uuid',
                Rule::exists(
                    'administrative_units',
                    'id'
                ),
            ],

        ];
    }

    /**
     * Custom messages.
     */
    public function messages(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'full_name.required' =>
                'Full name is required.',

            'national_id.required' =>
                'National ID is required.',

            'national_id.unique' =>
                'National ID already exists.',

            /*
            |--------------------------------------------------------------------------
            | Contact
            |--------------------------------------------------------------------------
            */

            'phone.required' =>
                'Phone number is required.',

            'phone.regex' =>
                'Enter a valid Ethiopian phone number.',

            'phone.unique' =>
                'Phone number already exists.',

            'email.email' =>
                'Enter a valid email address.',

            /*
            |--------------------------------------------------------------------------
            | Other
            |--------------------------------------------------------------------------
            */

            'gender.required' =>
                'Gender is required.',

            'administrative_unit_id.required' =>
                'Please select a Wereda.',

            'administrative_unit_id.exists' =>
                'Selected administrative unit does not exist.',

            'date_of_birth.before' =>
                'Birth date must be before today.',
        ];
    }

    /**
     * Friendly attribute names.
     */
    public function attributes(): array
    {
        return [

            'full_name' =>
                'full name',

            'national_id' =>
                'national ID',

            'date_of_birth' =>
                'birth date',

            'phone' =>
                'phone number',

            'email' =>
                'email address',

            'administrative_unit_id' =>
                'administrative unit',
        ];
    }
}
