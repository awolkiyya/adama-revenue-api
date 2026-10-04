<?php

namespace App\Modules\Agent\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentPendingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'search.string' => 'Search must be a valid value.',
            'search.max' => 'Search may not exceed 100 characters.',

            'page.integer' => 'Page must be a valid number.',
            'page.min' => 'Page must be at least 1.',

            'per_page.integer' => 'Per page must be a valid number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page may not exceed 100.',
        ];
    }
}