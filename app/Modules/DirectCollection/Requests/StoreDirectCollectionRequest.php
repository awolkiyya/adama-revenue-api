<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDirectCollectionRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user
     * is allowed to perform this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules for direct collection.
     *
     * Dynamic service-specific fields are intentionally
     * not hard-coded here. They are validated by the
     * DirectCollectionService against the selected
     * RevenueServiceField configuration.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Taxpayer
            |--------------------------------------------------------------------------
            */

            'taxpayer_id' => [
                'required',
                'uuid',
                Rule::exists('citizens', 'id'),
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            'revenue_service_id' => [
                'required',
                'uuid',
                Rule::exists('revenue_services', 'id'),
            ],

            /*
            |--------------------------------------------------------------------------
            | Dynamic Service Fields
            |--------------------------------------------------------------------------
            */

            'fields' => [
                'sometimes',
                'array',
            ],

            'fields.*' => [
                'nullable',
            ],

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Invoice Due Date
            |--------------------------------------------------------------------------
            */

            'due_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
        ];
    }

    /**
     * Normalize request values before validation.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        /*
        |--------------------------------------------------------------------------
        | Normalize taxpayer ID
        |--------------------------------------------------------------------------
        */

        if ($this->has('taxpayer_id')) {
            $data['taxpayer_id'] = $this->normalizeString(
                $this->input('taxpayer_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize revenue service ID
        |--------------------------------------------------------------------------
        */

        if ($this->has('revenue_service_id')) {
            $data['revenue_service_id'] = $this->normalizeString(
                $this->input('revenue_service_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize dynamic fields
        |--------------------------------------------------------------------------
        */

        if (
            $this->has('fields')
            && is_array($this->input('fields'))
        ) {
            $fields = [];

            foreach ($this->input('fields') as $key => $value) {
                $normalizedKey = is_string($key)
                    ? trim($key)
                    : $key;

                if (is_string($value)) {
                    $value = trim($value);
                }

                $fields[$normalizedKey] = $value;
            }

            $data['fields'] = $fields;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize notes
        |--------------------------------------------------------------------------
        */

        if ($this->has('notes')) {
            $data['notes'] = is_string($this->input('notes'))
                ? trim($this->input('notes'))
                : $this->input('notes');
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize due date
        |--------------------------------------------------------------------------
        */

        if ($this->has('due_date')) {
            $data['due_date'] = $this->normalizeString(
                $this->input('due_date')
            );
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    /**
     * Normalize a scalar string value.
     */
    private function normalizeString(mixed $value): mixed
    {
        return is_string($value)
            ? trim($value)
            : $value;
    }
}