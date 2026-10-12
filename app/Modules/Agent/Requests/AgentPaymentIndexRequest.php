<?php

namespace App\Modules\Agent\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentPaymentIndexRequest extends FormRequest
{
    /**
     * Determine whether the authenticated agent may make this request.
     *
     * Authentication and agent-specific access should also be enforced
     * by the route middleware and AgentPaymentService.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules for listing agent payments.
     *
     * Supported filters:
     * - per_page: Number of records per page.
     * - search: Search term.
     * - status: Payment status.
     */
    public function rules(): array
    {
        return [
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],

            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'PENDING',
                    'PROCESSING',
                    'COMPLETED',
                    'FAILED',
                    'CANCELLED',
                    'EXPIRED',
                ]),
            ],
        ];
    }

    /**
     * Normalize filter values before using them in the service.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('search') && is_string($this->input('search'))) {
            $this->merge([
                'search' => trim($this->input('search')),
            ]);
        }

        if ($this->has('status') && is_string($this->input('status'))) {
            $this->merge([
                'status' => strtoupper(trim($this->input('status'))),
            ]);
        }
    }
}
