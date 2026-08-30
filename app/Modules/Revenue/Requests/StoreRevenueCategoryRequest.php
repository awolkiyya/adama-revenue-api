<?php

namespace App\Modules\Revenue\Requests;

use App\Models\RevenueCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRevenueCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * --------------------------------------------------------------------------
     * Validation Rules
     * --------------------------------------------------------------------------
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Category
            |--------------------------------------------------------------------------
            */

            'revenue_domain' => [
                'required',
                Rule::in([
                    'TAX',
                    'RENT',
                    'INVESTMENT',
                    'SERVICE',
                    'SALE',
                    'CAPITAL',
                ]),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('revenue_categories')
                    ->where(fn ($query) => $query->where(
                        'revenue_domain',
                        $this->input('revenue_domain')
                    )),
            ],

            'start_code' => [
                'nullable',
                'integer',
                'required_with:end_code',
            ],

            'end_code' => [
                'nullable',
                'integer',
                'required_with:start_code',
                'gte:start_code',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
                Rule::unique('revenue_categories')
                    ->where(fn ($query) => $query->where(
                        'revenue_domain',
                        $this->input('revenue_domain')
                    )),
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Codes
            |--------------------------------------------------------------------------
            */

            'codes' => [
                'sometimes',
                'array',
            ],

            'codes.*.code' => [
                'required',
                'string',
                'max:20',
                'distinct',
                Rule::unique('revenue_codes', 'code'),
            ],

            'codes.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'codes.*.description' => [
                'nullable',
                'string',
            ],

            'codes.*.is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * --------------------------------------------------------------------------
     * Additional Business Validation
     * --------------------------------------------------------------------------
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {

            /**
             * --------------------------------------------------------------
             * Prevent overlapping category ranges
             * --------------------------------------------------------------
             */
            if ($this->filled('start_code') && $this->filled('end_code')) {

                $overlap = RevenueCategory::query()
                    ->where('revenue_domain', $this->revenue_domain)
                    ->where(function ($query) {

                        $query

                            ->whereBetween('start_code', [
                                $this->start_code,
                                $this->end_code,
                            ])

                            ->orWhereBetween('end_code', [
                                $this->start_code,
                                $this->end_code,
                            ])

                            ->orWhere(function ($query) {

                                $query
                                    ->where('start_code', '<=', $this->start_code)
                                    ->where('end_code', '>=', $this->end_code);

                            });

                    })
                    ->exists();

                if ($overlap) {

                    $validator->errors()->add(
                        'start_code',
                        'The selected code range overlaps an existing revenue category.'
                    );
                }
            }

            /**
             * --------------------------------------------------------------
             * Ensure every revenue code is inside the category range
             * --------------------------------------------------------------
             */
            if (
                $this->filled('start_code') &&
                $this->filled('end_code') &&
                is_array($this->codes)
            ) {

                foreach ($this->codes as $index => $code) {

                    if (
                        !isset($code['code']) ||
                        !is_numeric($code['code'])
                    ) {
                        continue;
                    }

                    $value = (int) $code['code'];

                    if (
                        $value < $this->start_code ||
                        $value > $this->end_code
                    ) {

                        $validator->errors()->add(
                            "codes.$index.code",
                            "Revenue code {$value} must be within the category range ({$this->start_code} - {$this->end_code})."
                        );
                    }
                }
            }
        });
    }

    /**
     * --------------------------------------------------------------------------
     * Normalize Input
     * --------------------------------------------------------------------------
     */
    protected function prepareForValidation(): void
    {
        $codes = collect($this->input('codes', []))
            ->map(function ($code) {

                return [

                    'code' => isset($code['code'])
                        ? trim((string) $code['code'])
                        : null,

                    'name' => isset($code['name'])
                        ? trim((string) $code['name'])
                        : null,

                    'description' => isset($code['description'])
                        ? trim((string) $code['description'])
                        : null,

                    'is_active' => filter_var(
                        $code['is_active'] ?? true,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ),

                ];
            })
            ->values()
            ->all();

        $this->merge([

            'name' => trim((string) $this->input('name')),

            'description' => $this->filled('description')
                ? trim((string) $this->description)
                : null,

            'is_active' => filter_var(
                $this->input('is_active', true),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ),

            'codes' => $codes,

        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Custom Messages
     * --------------------------------------------------------------------------
     */
    public function messages(): array
    {
        return [

            'name.unique' =>
                'A revenue category with this name already exists in the selected revenue domain.',

            'sort_order.unique' =>
                'This display order is already assigned within the selected revenue domain.',

            'start_code.required_with' =>
                'The start code is required when an end code is provided.',

            'end_code.required_with' =>
                'The end code is required when a start code is provided.',

            'end_code.gte' =>
                'The end code must be greater than or equal to the start code.',

            'codes.*.code.required' =>
                'Revenue code is required.',

            'codes.*.code.distinct' =>
                'Duplicate revenue codes are not allowed.',

            'codes.*.code.unique' =>
                'This revenue code already exists.',

            'codes.*.name.required' =>
                'Revenue code name is required.',
        ];
    }
}