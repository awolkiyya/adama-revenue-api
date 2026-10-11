<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCashReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash-reconciliations.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    'RECONCILED',
                    'DISCREPANCY',
                    'RETURNED',
                ]),
            ],

            'review_notes' => [
                'required_if:decision,DISCREPANCY,RETURNED',
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}