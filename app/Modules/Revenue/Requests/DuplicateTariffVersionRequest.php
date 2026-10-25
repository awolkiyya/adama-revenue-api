<?php

declare(strict_types=1);

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DuplicateTariffVersionRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user may duplicate
     * a tariff version.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('tariff_version.duplicate');
    }

    /**
     * Define the validation rules for creating a duplicate.
     *
     * The source version ID comes from the route.
     * The request body contains only the new version's attributes.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => [
                'required',
                'integer',
                'min:2000',
                'max:2200',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'effective_from' => [
                'required',
                'date_format:Y-m-d',
            ],

            'effective_to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:effective_from',
            ],
        ];
    }

    /**
     * Customize validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.required' => 'The target tariff year is required.',
            'year.integer' => 'The target tariff year must be a valid integer.',
            'year.min' => 'The target tariff year is outside the allowed range.',
            'year.max' => 'The target tariff year is outside the allowed range.',

            'name.required' => 'A name is required for the new tariff version.',
            'name.string' => 'The tariff version name must be text.',
            'name.max' => 'The tariff version name may not exceed 255 characters.',

            'description.string' => 'The description must be text.',
            'description.max' => 'The description may not exceed 2000 characters.',

            'effective_from.required' => 'The effective start date is required.',
            'effective_from.date_format' => 'The effective start date must use YYYY-MM-DD format.',

            'effective_to.date_format' => 'The effective end date must use YYYY-MM-DD format.',
            'effective_to.after_or_equal' => 'The effective end date must be on or after the effective start date.',
        ];
    }

    /**
     * Normalize text fields before validation.
     *
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name'))
                ? trim($this->input('name'))
                : $this->input('name'),

            'description' => is_string($this->input('description'))
                ? (trim($this->input('description')) ?: null)
                : $this->input('description'),
        ]);
    }
}
