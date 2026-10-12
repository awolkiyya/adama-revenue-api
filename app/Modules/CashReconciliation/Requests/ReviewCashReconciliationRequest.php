<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\CashReconciliation;

class ReviewCashReconciliationRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to review a reconciliation.
     *
     * The controller must additionally authorize the specific record
     * through CashReconciliationPolicy.
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
            'decision' => [
                'required',
                'string',
                Rule::in([
                    CashReconciliation::STATUS_RECONCILED,
                    CashReconciliation::STATUS_DISCREPANCY,
                    CashReconciliation::STATUS_RETURNED,
                ]),
            ],

            'review_notes' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:decision,DISCREPANCY',
                'required_if:decision,RETURNED',
            ],
        ];
    }

    /**
     * Apply conditional validation after the standard rules.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $decision = strtoupper(
                    trim((string) $this->input('decision'))
                );

                $notes = trim(
                    (string) $this->input('review_notes', '')
                );

                if (
                    in_array(
                        $decision,
                        [
                            CashReconciliation::STATUS_DISCREPANCY,
                            CashReconciliation::STATUS_RETURNED,
                        ],
                        true
                    )
                    && $notes === ''
                ) {
                    $validator->errors()->add(
                        'review_notes',
                        'Review notes are required when flagging a discrepancy or returning a reconciliation.'
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
            'decision.required' =>
                'Select a review decision.',

            'decision.in' =>
                'The selected review decision is invalid.',

            'review_notes.required_if' =>
                'Review notes are required for this decision.',

            'review_notes.string' =>
                'Review notes must be valid text.',

            'review_notes.max' =>
                'Review notes cannot exceed 5,000 characters.',
        ];
    }

    /**
     * Normalize decision and review notes.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('decision')) {
            $this->merge([
                'decision' => strtoupper(
                    trim((string) $this->input('decision'))
                ),
            ]);
        }

        if ($this->has('review_notes')) {
            $notes = $this->input('review_notes');

            $this->merge([
                'review_notes' => is_string($notes)
                    ? trim($notes)
                    : $notes,
            ]);
        }
    }
}
