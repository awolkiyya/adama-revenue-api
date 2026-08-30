<?php

namespace App\Modules\Audit\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemLogIndexRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Authorization is handled by SystemLogPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for audit-log listing.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Filters
            |--------------------------------------------------------------------------
            */

            'action' => [
                'nullable',
                'string',
                'max:100',
            ],

            'module' => [
                'nullable',
                'string',
                'max:100',
            ],

            'user_id' => [
                'nullable',
                'uuid',
                'exists:users,id',
            ],

            'resource_type' => [
                'nullable',
                'string',
                'max:255',
            ],

            'resource_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'request_id' => [
                'nullable',
                'uuid',
            ],

            'ip_address' => [
                'nullable',
                'ip',
            ],

            /*
            |--------------------------------------------------------------------------
            | Date Filters
            |--------------------------------------------------------------------------
            */

            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
            ],

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */

            'sort_by' => [
                'nullable',
                'string',
                'in:created_at,action,module,user_id,resource_type',
            ],

            'sort_direction' => [
                'nullable',
                'string',
                'in:asc,desc',
            ],
        ];
    }

    /**
     * Normalize validated input.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search')
                ? trim((string) $this->input('search'))
                : null,

            'action' => $this->filled('action')
                ? strtoupper(trim((string) $this->input('action')))
                : null,

            'module' => $this->filled('module')
                ? strtolower(trim((string) $this->input('module')))
                : null,

            'resource_type' => $this->filled('resource_type')
                ? trim((string) $this->input('resource_type'))
                : null,

            'resource_id' => $this->filled('resource_id')
                ? trim((string) $this->input('resource_id'))
                : null,

            'sort_by' => $this->input('sort_by', 'created_at'),

            'sort_direction' => strtolower(
                (string) $this->input('sort_direction', 'desc')
            ),

            'per_page' => $this->input('per_page', 20),
        ]);
    }
}