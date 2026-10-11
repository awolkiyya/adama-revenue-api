<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash-reconciliations.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'period_start' => [
                'required',
                'date',
            ],

            'period_end' => [
                'required',
                'date',
                'after_or_equal:period_start',
            ],

            'collector_notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}