<?php

namespace App\Modules\CashReconciliation\Requests;

use App\Models\CashReconciliation;
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
            /*
            |--------------------------------------------------------------------------
            | Closing period
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Actual cash amount
            |--------------------------------------------------------------------------
            */

            'actual_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],

            /*
            |--------------------------------------------------------------------------
            | Collector notes
            |--------------------------------------------------------------------------
            */

            'collector_notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            /*
            |--------------------------------------------------------------------------
            | New evidence uploads
            |--------------------------------------------------------------------------
            |
            | The frontend sends newly selected files using:
            |
            | evidence[]
            |
            | Maximum:
            | - 10 files per request
            | - 10 MB per file
            |
            */

            'evidence' => [
                'sometimes',
                'array',
                'max:10',
            ],

            'evidence.*' => [
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png',
            ],

            /*
            |--------------------------------------------------------------------------
            | Existing evidence removal
            |--------------------------------------------------------------------------
            |
            | The frontend sends database file IDs:
            |
            | remove_evidence_ids[]
            |
            | These are strings, not integers.
            |
            | Ownership must additionally be verified against:
            | $reconciliation->evidence()
            |
            */

            'remove_evidence_ids' => [
                'sometimes',
                'array',
                'max:50',
            ],

            'remove_evidence_ids.*' => [
                'required',
                'string',
                'max:64',
                'distinct',
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

                if ($start === null && $end === null) {
                    return;
                }

                /*
                 * Support both route model binding and route IDs.
                 */
                $reconciliation = $this->route('cashReconciliation')
                    ?? $this->route('cash_reconciliation')
                    ?? $this->route('id');

                if (
                    is_string($reconciliation)
                    || is_numeric($reconciliation)
                ) {
                    $reconciliation = CashReconciliation::query()
                        ->find($reconciliation);
                }

                if (
                    ! $reconciliation instanceof CashReconciliation
                ) {
                    return;
                }

                /*
                 * Use submitted values when present.
                 * Otherwise, use the existing database values.
                 */
                $effectiveStart = $start
                    ?? $reconciliation->period_start;

                $effectiveEnd = $end
                    ?? $reconciliation->period_end;

                if (
                    $effectiveStart === null
                    || $effectiveEnd === null
                ) {
                    return;
                }

                $startTimestamp = strtotime(
                    (string) $effectiveStart
                );

                $endTimestamp = strtotime(
                    (string) $effectiveEnd
                );

                if (
                    $startTimestamp !== false
                    && $endTimestamp !== false
                    && $endTimestamp < $startTimestamp
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
            /*
            |--------------------------------------------------------------------------
            | Closing period
            |--------------------------------------------------------------------------
            */

            'period_start.required' =>
                'The reconciliation period start is required.',

            'period_start.date' =>
                'The reconciliation period start must be a valid date.',

            'period_end.required' =>
                'The reconciliation period end is required.',

            'period_end.date' =>
                'The reconciliation period end must be a valid date.',

            /*
            |--------------------------------------------------------------------------
            | Actual amount
            |--------------------------------------------------------------------------
            */

            'actual_amount.numeric' =>
                'The actual cash amount must be a valid number.',

            'actual_amount.min' =>
                'The actual cash amount cannot be negative.',

            'actual_amount.decimal' =>
                'The actual cash amount may have at most two decimal places.',

            /*
            |--------------------------------------------------------------------------
            | Collector notes
            |--------------------------------------------------------------------------
            */

            'collector_notes.string' =>
                'Collector notes must be valid text.',

            'collector_notes.max' =>
                'Collector notes cannot exceed 5,000 characters.',

            /*
            |--------------------------------------------------------------------------
            | Evidence uploads
            |--------------------------------------------------------------------------
            */

            'evidence.array' =>
                'The evidence files must be submitted as a valid list.',

            'evidence.max' =>
                'You cannot upload more than 10 evidence files at once.',

            'evidence.*.required' =>
                'Each evidence attachment is required.',

            'evidence.*.file' =>
                'Each evidence attachment must be a valid file.',

            'evidence.*.max' =>
                'Each evidence file cannot exceed 10 MB.',

            'evidence.*.mimes' =>
                'Evidence files must be PDF, JPG, JPEG, PNG, WEBP, DOC, DOCX, XLS, XLSX, or CSV.',

            /*
            |--------------------------------------------------------------------------
            | Evidence removal
            |--------------------------------------------------------------------------
            */

            'remove_evidence_ids.array' =>
                'The evidence removal IDs must be submitted as a valid list.',

            'remove_evidence_ids.max' =>
                'You cannot remove more than 50 evidence files in one request.',

            'remove_evidence_ids.*.required' =>
                'An evidence removal ID is required.',

            'remove_evidence_ids.*.string' =>
                'Each evidence removal ID must be a valid string.',

            'remove_evidence_ids.*.max' =>
                'An evidence removal ID cannot exceed 64 characters.',

            'remove_evidence_ids.*.distinct' =>
                'Duplicate evidence removal IDs are not allowed.',
        ];
    }

    /**
     * Normalize optional notes before validation.
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
