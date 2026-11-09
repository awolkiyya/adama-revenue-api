<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateDirectCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Validate the direct collection calculation request.
     *
     * Dynamic field definitions, field values, and the
     * allow_multiple service restriction are validated by
     * DirectCollectionInputService.
     *
     * @return array<string, mixed>
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
                'exists:citizens,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            'revenue_service_id' => [
                'required',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Collection Entries
            |--------------------------------------------------------------------------
            |
            | Each entry contains its own independent set of
            | dynamic field values.
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
            | Administrative Unit
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'nullable',
                'uuid',
            ],
        ];
    }

    /**
     * Normalize legacy single-entry request formats.
     *
     * Supported formats:
     *
     * 1. New format:
     *    entries: [
     *        ['fields' => [...]],
     *        ['fields' => [...]],
     *    ]
     *
     * 2. Legacy format:
     *    fields: [...]
     *
     * 3. Legacy format:
     *    inputs: [...]
     */
    protected function prepareForValidation(): void
    {
        // Preserve the new multi-entry format when supplied.
        if ($this->has('entries')) {
            return;
        }

        $fields = $this->input('fields');

        // Support the older "inputs" property.
        if (! is_array($fields)) {
            $fields = $this->input('inputs');
        }

        if (! is_array($fields)) {
            return;
        }

        $this->merge([
            'entries' => [
                [
                    'fields' => $fields,
                ],
            ],
        ]);
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'taxpayer_id.required' =>
                'Taxpayer is required.',

            'taxpayer_id.uuid' =>
                'The selected taxpayer ID must be a valid UUID.',

            'taxpayer_id.exists' =>
                'The selected taxpayer could not be found.',

            'revenue_service_id.required' =>
                'Revenue service is required.',

            'revenue_service_id.uuid' =>
                'The revenue service ID must be a valid UUID.',

            'revenue_service_id.exists' =>
                'The selected revenue service could not be found.',

            'entries.required' =>
                'At least one collection entry is required.',

            'entries.array' =>
                'Collection entries must be provided as an array.',

            'entries.min' =>
                'At least one collection entry is required.',

            'entries.*.required' =>
                'Each collection entry is required.',

            'entries.*.array' =>
                'Each collection entry must be an object.',

            'entries.*.fields.required' =>
                'Fields are required for each collection entry.',

            'entries.*.fields.array' =>
                'Collection entry fields must be provided as an array.',

            'administrative_unit_id.uuid' =>
                'The administrative unit ID must be a valid UUID.',
        ];
    }
}