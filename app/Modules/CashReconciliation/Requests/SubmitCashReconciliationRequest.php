<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCashReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash-reconciliations.submit') ?? false;
    }

    public function rules(): array
    {
        return [
            'actual_amount' => [
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],

            'collector_notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}