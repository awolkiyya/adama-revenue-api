<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCashReconciliationRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to submit a reconciliation.
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
            'actual_amount' => [
                'required',
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
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'actual_amount.required' =>
                'Enter the actual cash amount before submitting the reconciliation.',

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
     * Normalize optional collector notes.
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
