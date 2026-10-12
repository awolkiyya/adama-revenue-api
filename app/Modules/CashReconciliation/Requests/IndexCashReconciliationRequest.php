<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\CashReconciliation;

class IndexCashReconciliationRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
    

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    CashReconciliation::STATUS_DRAFT,
                    CashReconciliation::STATUS_PENDING_REVIEW,
                    CashReconciliation::STATUS_RECONCILED,
                    CashReconciliation::STATUS_DISCREPANCY,
                    CashReconciliation::STATUS_RETURNED,
                    CashReconciliation::STATUS_CANCELLED,
                ]),
            ],

            'collector_id' => [
                'nullable',
                'uuid',
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

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'sort_by' => [
                'nullable',
                'string',
                Rule::in([
                    'created_at',
                    'period_start',
                    'period_end',
                    'expected_amount',
                    'actual_amount',
                    'difference_amount',
                    'submitted_at',
                    'reviewed_at',
                    'status',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                'string',
                Rule::in(['asc', 'desc']),
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The selected reconciliation status is invalid.',

            'collector_id.uuid' => 'The collector ID must be a valid UUID.',

            'period_start.date' => 'The period start must be a valid date.',
            'period_end.date' => 'The period end must be a valid date.',
            'period_end.after_or_equal' =>
                'The period end must be on or after the period start.',

            'from.date' => 'The start filter must be a valid date.',
            'to.date' => 'The end filter must be a valid date.',
            'to.after_or_equal' =>
                'The end filter must be on or after the start filter.',

            'per_page.integer' => 'The page size must be an integer.',
            'per_page.min' => 'The page size must be at least 1.',
            'per_page.max' => 'The page size cannot exceed 100.',

            'page.integer' => 'The page number must be an integer.',
            'page.min' => 'The page number must be at least 1.',

            'sort_by.in' => 'The selected sorting field is invalid.',
            'sort_direction.in' =>
                'The sorting direction must be asc or desc.',
        ];
    }

    /**
     * Normalize filter values before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->filled('status')
                ? strtoupper(trim((string) $this->input('status')))
                : null,

            'sort_direction' => $this->filled('sort_direction')
                ? strtolower(trim((string) $this->input('sort_direction')))
                : null,
        ]);
    }
}
