<?php

/*
|--------------------------------------------------------------------------
| FILE: app/Modules/CashReconciliation/Requests/ExpectedByCollectorRequest.php
|--------------------------------------------------------------------------
*/

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpectedByCollectorRequest extends FormRequest
{
    /**
     * Determine whether the request is authorized.
     *
     * Actual authorization is handled by the controller/policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Collection Period
            |--------------------------------------------------------------------------
            |
            | Defines the period used to calculate eligible cash collections
            | and their corresponding reconciled amounts.
            |
            */

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],

            /*
            |--------------------------------------------------------------------------
            | Collector Filtering
            |--------------------------------------------------------------------------
            */

            'collector_id' => [
                'nullable',
                'ulid',
            ],

            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Outstanding Cash Sorting
            |--------------------------------------------------------------------------
            |
            | Sorts collectors by their calculated collection balances.
            |
            */

            'sort_by' => [
                'nullable',
                Rule::in([
                    'expected',
                    'reconciled',
                    'outstanding',
                ]),
            ],

            'sort_dir' => [
                'nullable',
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
}
