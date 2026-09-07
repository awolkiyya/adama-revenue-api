<?php

namespace App\Modules\Revenue\Requests;

use App\Models\InterestRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterestRuleRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
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
            | Interest Rate
            |--------------------------------------------------------------------------
            |
            | Stored as a percentage.
            |
            | Examples:
            |
            | 24.7250 = 24.725%
            | 2.0000  = 2%
            | 0.0500  = 0.05%
            |
            */

            'rate' => [
                'required',
                'numeric',
                'min:0',
                'max:999999.9999',
                'decimal:0,4',
            ],

            /*
            |--------------------------------------------------------------------------
            | Rate Period
            |--------------------------------------------------------------------------
            */

            'rate_period' => [
                'required',
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
                'required',
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
                'required',
                'string',
                Rule::in([
                    InterestRule::BASIS_PRINCIPAL,
                    InterestRule::BASIS_OUTSTANDING,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Effective Period
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

            /*
            |--------------------------------------------------------------------------
            | Legal Information
            |--------------------------------------------------------------------------
            */

            'legal_reference' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    /**
     * Prepare normalized input before validation.
     */
    protected function prepareForValidation(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Normalize Rate
        |--------------------------------------------------------------------------
        */

        if ($this->has('rate')) {
            $rate = $this->input('rate');

            if (
                is_string($rate)
            ) {
                $rate = trim($rate);
            }

            $this->merge([
                'rate' => $rate,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Rate Period
        |--------------------------------------------------------------------------
        */

        if ($this->has('rate_period')) {
            $this->merge([
                'rate_period' => strtoupper(
                    trim(
                        (string) $this->input(
                            'rate_period'
                        )
                    )
                ),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Calculation Method
        |--------------------------------------------------------------------------
        */

        if ($this->has('calculation_method')) {
            $this->merge([
                'calculation_method' => strtoupper(
                    trim(
                        (string) $this->input(
                            'calculation_method'
                        )
                    )
                ),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Calculation Basis
        |--------------------------------------------------------------------------
        */

        if ($this->has('calculation_basis')) {
            $this->merge([
                'calculation_basis' => strtoupper(
                    trim(
                        (string) $this->input(
                            'calculation_basis'
                        )
                    )
                ),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Legal Reference
        |--------------------------------------------------------------------------
        */

        if ($this->has('legal_reference')) {
            $this->merge([
                'legal_reference' =>
                    $this->filled('legal_reference')
                        ? trim(
                            (string) $this->input(
                                'legal_reference'
                            )
                        )
                        : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Description
        |--------------------------------------------------------------------------
        */

        if ($this->has('description')) {
            $this->merge([
                'description' =>
                    $this->filled('description')
                        ? trim(
                            (string) $this->input(
                                'description'
                            )
                        )
                        : null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Default Status
        |--------------------------------------------------------------------------
        */

        if (! $this->has('is_active')) {
            $this->merge([
                'is_active' => true,
            ]);
        }
    }
}