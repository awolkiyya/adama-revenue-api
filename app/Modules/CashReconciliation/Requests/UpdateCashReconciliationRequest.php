<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashReconciliationRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to update a reconciliation.
     *
     * Record ownership and status are enforced by the policy and service.
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

    /**
     * Validate the complete period, including an unchanged date
     * that was not included in a partial update.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $start = $this->input('period_start');
                $end = $this->input('period_end');

                if (
                    $start === null
                    && $end === null
                ) {
                    return;
                }

                $reconciliation = $this->route('cash_reconciliation')
                    ?? $this->route('cashReconciliation')
                    ?? $this->route('id');

                if (is_string($reconciliation) || is_numeric($reconciliation)) {
                    $reconciliation = \App\Models\CashReconciliation::query()
                        ->find($reconciliation);
                }

                if (! $reconciliation instanceof \App\Models\CashReconciliation) {
                    return;
                }

                $effectiveStart = $start ?? $reconciliation->period_start;
                $effectiveEnd = $end ?? $reconciliation->period_end;

                if (
                    $effectiveStart !== null
                    && $effectiveEnd !== null
                    && strtotime((string) $effectiveEnd)
                        < strtotime((string) $effectiveStart)
                ) {
                    $validator->errors()->add(
                        'period_end',
                        'The period end must be on or after the period start.'
                    );
                }
            },
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'period_start.required' =>
                'The reconciliation period start is required.',

            'period_start.date' =>
                'The reconciliation period start must be a valid date.',

            'period_end.required' =>
                'The reconciliation period end is required.',

            'period_end.date' =>
                'The reconciliation period end must be a valid date.',

            'actual_amount.numeric' =>
                'The actual cash amount must be a valid number.',

            'actual_amount.min' =>
                'The actual cash amount cannot be negative.',

            'actual_amount.decimal' =>
                'The actual cash amount may have at most two decimal places.',

            'collector_notes.string' =>
                'Collector notes must be valid text.',

            'collector_notes.max' =>
                'Collector notes cannot exceed 5,000 characters.',
        ];
    }

    /**
     * Normalize optional notes.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('collector_notes')) {
            $notes = $this->input('collector_notes');

            $this->merge([
                'collector_notes' => is_string($notes)
                    ? trim($notes)
                    : $notes,
            ]);
        }
    }
}
