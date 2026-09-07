<?php

namespace App\Modules\Revenue\Requests;

use App\Models\InterestRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInterestRuleRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'viewAny',
            InterestRule::class
        ) ?? false;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => [
                'nullable',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Rate Period
            |--------------------------------------------------------------------------
            */

            'rate_period' => [
                'nullable',
                'string',
                Rule::in([
                    InterestRule::RATE_PERIOD_YEAR,
                    InterestRule::RATE_PERIOD_MONTH,
                    InterestRule::RATE_PERIOD_DAY,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Method
            |--------------------------------------------------------------------------
            */

            'calculation_method' => [
                'nullable',
                'string',
                Rule::in([
                    InterestRule::METHOD_SIMPLE,
                    InterestRule::METHOD_COMPOUND,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            'calculation_basis' => [
                'nullable',
                'string',
                Rule::in([
                    InterestRule::BASIS_PRINCIPAL,
                    InterestRule::BASIS_OUTSTANDING,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Effective Date
            |--------------------------------------------------------------------------
            |
            | Filters rules applicable to a specific date.
            |
            */

            'effective_date' => [
                'nullable',
                'date',
            ],

            /*
            |--------------------------------------------------------------------------
            | Legacy / Optional Effective Range Filters
            |--------------------------------------------------------------------------
            |
            | Keep these if your frontend or existing API consumers still
            | use effective_from/effective_to as independent filters.
            |
            */

            'effective_from' => [
                'nullable',
                'date',
            ],

            'effective_to' => [
                'nullable',
                'date',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */

            'sort_by' => [
                'nullable',
                'string',
                Rule::in([
                    'rate',
                    'rate_period',
                    'calculation_method',
                    'calculation_basis',
                    'effective_from',
                    'effective_to',
                    'is_active',
                    'created_at',
                    'updated_at',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                'string',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }

    /**
     * Normalize query parameters.
     */
    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($this->has('search')) {
            $this->merge([
                'search' => $this->filled('search')
                    ? trim(
                        (string) $this->input('search')
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Boolean Status
        |--------------------------------------------------------------------------
        */

        if ($this->has('is_active')) {
            $value = $this->input('is_active');

            if (is_string($value)) {
                $this->merge([
                    'is_active' => filter_var(
                        $value,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Period
        |--------------------------------------------------------------------------
        */

        if ($this->has('rate_period')) {
            $this->merge([
                'rate_period' => $this->filled('rate_period')
                    ? strtoupper(
                        trim(
                            (string) $this->input(
                                'rate_period'
                            )
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Method
        |--------------------------------------------------------------------------
        */

        if ($this->has('calculation_method')) {
            $this->merge([
                'calculation_method' => $this->filled(
                    'calculation_method'
                )
                    ? strtoupper(
                        trim(
                            (string) $this->input(
                                'calculation_method'
                            )
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis
        |--------------------------------------------------------------------------
        */

        if ($this->has('calculation_basis')) {
            $this->merge([
                'calculation_basis' => $this->filled(
                    'calculation_basis'
                )
                    ? strtoupper(
                        trim(
                            (string) $this->input(
                                'calculation_basis'
                            )
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective Date
        |--------------------------------------------------------------------------
        */

        if ($this->has('effective_date')) {
            $this->merge([
                'effective_date' => $this->filled(
                    'effective_date'
                )
                    ? trim(
                        (string) $this->input(
                            'effective_date'
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective From
        |--------------------------------------------------------------------------
        */

        if ($this->has('effective_from')) {
            $this->merge([
                'effective_from' => $this->filled(
                    'effective_from'
                )
                    ? trim(
                        (string) $this->input(
                            'effective_from'
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective To
        |--------------------------------------------------------------------------
        */

        if ($this->has('effective_to')) {
            $this->merge([
                'effective_to' => $this->filled(
                    'effective_to'
                )
                    ? trim(
                        (string) $this->input(
                            'effective_to'
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Sort By
        |--------------------------------------------------------------------------
        */

        if ($this->has('sort_by')) {
            $this->merge([
                'sort_by' => $this->filled('sort_by')
                    ? trim(
                        (string) $this->input(
                            'sort_by'
                        )
                    )
                    : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Sort Direction
        |--------------------------------------------------------------------------
        */

        if ($this->has('sort_direction')) {
            $this->merge([
                'sort_direction' => $this->filled(
                    'sort_direction'
                )
                    ? strtolower(
                        trim(
                            (string) $this->input(
                                'sort_direction'
                            )
                        )
                    )
                    : null,
            ]);
        }
    }
}