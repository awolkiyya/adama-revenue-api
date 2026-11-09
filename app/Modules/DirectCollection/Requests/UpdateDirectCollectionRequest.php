<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDirectCollectionRequest extends FormRequest
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
     * Validation rules for updating a direct collection.
     *
     * Dynamic service-field validation is handled by
     * DirectCollectionInputService.
     *
     * Financial values are never accepted from the client.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Taxpayer
            |--------------------------------------------------------------------------
            |
            | Either taxpayer_id or citizen_id may be supplied.
            |
            */

            'taxpayer_id' => [
                'nullable',
                'uuid',
                'exists:citizens,id',
            ],

            'citizen_id' => [
                'nullable',
                'uuid',
                'exists:citizens,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            |
            | Either revenue_service_id or service_id may be supplied.
            |
            */

            'revenue_service_id' => [
                'nullable',
                'uuid',
                'exists:revenue_services,id',
            ],

            'service_id' => [
                'nullable',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Collection Entries
            |--------------------------------------------------------------------------
            |
            | Each entry contains its own dynamic service fields.
            |
            */

            'entries' => [
                'sometimes',
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
            | Legacy Dynamic Fields
            |--------------------------------------------------------------------------
            |
            | Supported for backward compatibility.
            |
            */

            'fields' => [
                'nullable',
                'array',
            ],

            'fields.*' => [
                'nullable',
            ],

            'inputs' => [
                'nullable',
                'array',
            ],

            'inputs.*' => [
                'nullable',
            ],

            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'nullable',
                'uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Edit Reason
            |--------------------------------------------------------------------------
            */

            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Financial Fields
            |--------------------------------------------------------------------------
            |
            | Never accept these from the client:
            *
            * amount
            * currency
            * quantity
            * unit
            * unit_price
            * tariff_version
            * tariff_rule
            * penalty_rule
            * interest_rule
            * due_date
            * calculation_snapshot
            * input_snapshot
            |
            | These values must always be calculated or generated
            | by the backend.
            |
            */
        ];
    }

    /**
     * Normalize alternative input names and collection entries.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        /*
        |--------------------------------------------------------------------------
        | Normalize taxpayer aliases
        |--------------------------------------------------------------------------
        */

        if (
            ! $this->has('taxpayer_id')
            && $this->has('citizen_id')
        ) {
            $data['taxpayer_id'] = $this->normalizeString(
                $this->input('citizen_id')
            );
        } elseif ($this->has('taxpayer_id')) {
            $data['taxpayer_id'] = $this->normalizeString(
                $this->input('taxpayer_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize revenue service aliases
        |--------------------------------------------------------------------------
        */

        if (
            ! $this->has('revenue_service_id')
            && $this->has('service_id')
        ) {
            $data['revenue_service_id'] = $this->normalizeString(
                $this->input('service_id')
            );
        } elseif ($this->has('revenue_service_id')) {
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
         * Convert legacy fields or inputs into one collection entry
         * when the new entries structure is not supplied.
         */

        if (! $this->has('entries')) {
            $legacyFields = $this->input('fields');

            if (! is_array($legacyFields)) {
                $legacyFields = $this->input('inputs');
            }

            if (is_array($legacyFields)) {
                $entries = [
                    [
                        'fields' => $legacyFields,
                    ],
                ];
            }
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

        if (
            $this->has('inputs')
            && is_array($this->input('inputs'))
        ) {
            $data['inputs'] = $this->normalizeFields(
                $this->input('inputs')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize administrative unit ID
        |--------------------------------------------------------------------------
        */

        if ($this->has('administrative_unit_id')) {
            $data['administrative_unit_id'] = $this->normalizeString(
                $this->input('administrative_unit_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize notes and reason
        |--------------------------------------------------------------------------
        */

        foreach (['notes', 'reason'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->normalizeString(
                    $this->input($field)
                );
            }
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
