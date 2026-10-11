<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCashReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash-reconciliations.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'DRAFT',
                    'PENDING_REVIEW',
                    'RECONCILED',
                    'DISCREPANCY',
                    'RETURNED',
                    'CANCELLED',
                ]),
            ],

            'collector_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'period_start' => [
                'nullable',
                'date',
            ],

            'period_end' => [
                'nullable',
                'date',
                'after_or_equal:period_start',
            ],

            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
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