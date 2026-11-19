<?php

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashReconciliationRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to create a reconciliation.
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

            // Newly uploaded evidence files.
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

            'period_end.after_or_equal' =>
                'The reconciliation period end must be on or after the start.',

            'collector_notes.string' =>
                'Collector notes must be valid text.',

            'collector_notes.max' =>
                'Collector notes cannot exceed 5,000 characters.',

            'evidence.array' =>
                'The evidence files must be submitted as a valid list.',

            'evidence.max' =>
                'You cannot upload more than 10 evidence files at once.',

            'evidence.*.file' =>
                'Each evidence attachment must be a valid file.',

            'evidence.*.max' =>
                'Each evidence file cannot exceed 10 MB.',

            'evidence.*.mimes' =>
                'Evidence files must be PDF, JPG, JPEG, PNG, WEBP, DOC, DOCX, XLS, or XLSX.',
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
