<?php

namespace App\Modules\Revenue\Requests;

use App\Models\TariffVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTariffVersionRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }


    /**
     * Get validation rules.
     */
    public function rules(): array
    {
        $tariffVersionId = $this->route('tariff_version');


        return [

            /*
            |--------------------------------------------------------------------------
            | Tariff Information
            |--------------------------------------------------------------------------
            */

            'year' => [
                'required',
                'integer',
                'digits:4',
                'min:2000',
                'max:9999',
            ],


            'name' => [

                'required',

                'string',

                'max:150',

            ],


            'description' => [

                'nullable',

                'string',

                'max:5000',

            ],


            /*
            |--------------------------------------------------------------------------
            | Effective Dates
            |--------------------------------------------------------------------------
            */

            'effective_from' => [

                'required',

                'date',

            ],


            'effective_to' => [

                'nullable',

                'date',

                'after_or_equal:effective_from',

            ],


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => [

                'sometimes',

                'boolean',

            ],

        ];
    }



    /**
     * Prepare request data.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {

            $this->merge([

                'is_active' =>
                    filter_var(
                        $this->is_active,
                        FILTER_VALIDATE_BOOLEAN
                    ),

            ]);

        }
    }



    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [

            'effective_to.after_or_equal' =>
                'The effective end date must be after or equal to the effective start date.',

        ];
    }



    /**
     * Custom attribute names.
     */
    public function attributes(): array
    {
        return [

            'year' =>
                'tariff year',

            'effective_from' =>
                'effective from date',


            'effective_to' =>
                'effective to date',


            'is_active' =>
                'active status',

        ];
    }
}