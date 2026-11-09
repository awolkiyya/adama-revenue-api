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
     * Revenue-service-specific fields are validated by
     * DirectCollectionInputService.
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
            | Collection Entries
            |--------------------------------------------------------------------------
            |
            | One direct collection can contain multiple entries
            | for the same taxpayer and revenue service.
            |
            */

            'entries' => [
                'required',
                'array',
                'min:1',
            ],

            'entries.*' => [
                'required',
                'array',
            ],

            'entries.*.fields' => [
                'required',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Legacy Single-Entry Fields
            |--------------------------------------------------------------------------
            |
            | Supported for backward compatibility.
            | prepareForValidation() converts these into entries.
            |
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
        | Normalize collection entries
        |--------------------------------------------------------------------------
        */

        $entries = $this->input('entries');

        /*
         * Backward compatibility:
         *
         * Convert the old single-entry format:
         *
         * {
         *     "fields": {
         *         "field-uuid": "value"
         *     }
         * }
         *
         * Into the new format:
         *
         * {
         *     "entries": [
         *         {
         *             "fields": {
         *                 "field-uuid": "value"
         *             }
         *         }
         *     ]
         * }
         */

        if (
            ! $this->has('entries')
            && is_array($this->input('fields'))
        ) {
            $entries = [
                [
                    'fields' => $this->input('fields'),
                ],
            ];
        }

        if (is_array($entries)) {
            $normalizedEntries = [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    $normalizedEntries[] = $entry;
                    continue;
                }

                if (
                    isset($entry['fields'])
                    && is_array($entry['fields'])
                ) {
                    $entry['fields'] = $this->normalizeFields(
                        $entry['fields']
                    );
                }

                $normalizedEntries[] = $entry;
            }

            $data['entries'] = $normalizedEntries;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize legacy fields
        |--------------------------------------------------------------------------
        */

        if (
            $this->has('fields')
            && is_array($this->input('fields'))
        ) {
            $data['fields'] = $this->normalizeFields(
                $this->input('fields')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize notes
        |--------------------------------------------------------------------------
        */

        if ($this->has('notes')) {
            $data['notes'] = $this->normalizeString(
                $this->input('notes')
            );
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    /**
     * Normalize dynamic service field values.
     *
     * Field UUIDs are preserved as array keys.
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $key => $value) {
            $normalizedKey = is_string($key)
                ? trim($key)
                : $key;

            if (is_string($value)) {
                $value = trim($value);
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
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
