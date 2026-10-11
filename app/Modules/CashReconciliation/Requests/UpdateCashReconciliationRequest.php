<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash-reconciliations.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'period_start' => [
                'sometimes',
                'required',
                'date',
            ],

            'period_end' => [
                'sometimes',
                'required',
                'date',
            ],

            'actual_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],

            'collector_notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $start = $this->input(
                'period_start',
                $this->route('cashReconciliation')?->period_start
            );

            $end = $this->input(
                'period_end',
                $this->route('cashReconciliation')?->period_end
            );

            if ($start && $end && strtotime($end) < strtotime($start)) {
                $validator->errors()->add(
                    'period_end',
                    'The closing period end must be on or after its start.'
                );
            }
        });
    }
}