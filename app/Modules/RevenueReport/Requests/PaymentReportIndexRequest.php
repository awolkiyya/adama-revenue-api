<?php

namespace App\Modules\RevenueReport\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentReportIndexRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate payment report filters.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Reporting Period
            |--------------------------------------------------------------------------
            */

            'start_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'end_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Filters
            |--------------------------------------------------------------------------
            */

            'status' => [
                'nullable',
                Rule::in([
                    'PENDING',
                    'PROCESSING',
                    'COMPLETED',
                    'FAILED',
                    'CANCELLED',
                    'EXPIRED',
                    'REVERSED',
                ]),
            ],

            'payment_method' => [
                'nullable',
                Rule::in([
                    'CASH',
                    'BANK_TRANSFER',
                    'ONLINE',
                ]),
            ],

            'payment_provider' => [
                'nullable',
                'string',
                'max:100',
            ],

            'payment_source' => [
                'nullable',
                Rule::in([
                    'ASSESSMENT',
                    'DIRECT_COLLECTION',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Administrative and Revenue Classification Filters
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'nullable',
                'uuid',
            ],

            'revenue_category_id' => [
                'nullable',
                'uuid',
            ],

            'service_id' => [
                'nullable',
                'uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */

            'sort_by' => [
                'nullable',
                Rule::in([
                    'created_at',
                    'amount',
                    'status',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],
        ];
    }

    /**
     * Return only validated report filters.
     */
    public function filters(): array
    {
        return $this->validated();
    }
}
