<?php

namespace App\Modules\Assessment\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreLeaseAmendmentRequest extends FormRequest
{
    /**
     * Authorize the request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the frontend payload before validation.
     *
     * Supports:
     * - assessmentId / previous_assessment_id
     * - values as an array or JSON string
     * - camelCase and snake_case fields
     * - multipart supporting documents
     */
    protected function prepareForValidation(): void
    {
        $values = $this->input('values');

        if (is_string($values)) {
            $decoded = json_decode($values, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $values = $decoded;
            }
        }

        if (! is_array($values)) {
            $values = [];
        }

        $getValue = function (
            string $snakeCase,
            string $camelCase
        ) use ($values) {
            if (array_key_exists($snakeCase, $values)) {
                return $values[$snakeCase];
            }

            if (array_key_exists($camelCase, $values)) {
                return $values[$camelCase];
            }

            if ($this->exists($snakeCase)) {
                return $this->input($snakeCase);
            }

            if ($this->exists($camelCase)) {
                return $this->input($camelCase);
            }

            return null;
        };

        $normalized = [
            'previous_assessment_id' => $this->input('previous_assessment_id')
                ?? $this->input('assessmentId'),

            'amendment_type' => $getValue(
                'amendment_type',
                'amendmentType'
            ),

            'new_taxpayer_id' => $getValue(
                'new_taxpayer_id',
                'newTaxpayerId'
            ),

            'new_land_area' => $getValue(
                'new_land_area',
                'newLandArea'
            ),

            'transfer_area' => $getValue(
                'transfer_area',
                'transferArea'
            ),

            'other_land_area' => $getValue(
                'other_land_area',
                'mergedLandArea'
            ),

            'other_amendment_description' => $getValue(
                'other_amendment_description',
                'otherAmendmentDescription'
            ),

            'reason' => $getValue('reason', 'reason'),
        ];

        /*
         * Preserve existing top-level values when the corresponding
         * nested value is absent.
         */
        $this->merge(array_filter(
            $normalized,
            fn ($value, $key) => $value !== null || ! $this->exists($key),
            ARRAY_FILTER_USE_BOTH
        ));

        /*
         * Normalize optional change records.
         */
        if (isset($values['changes']) && is_array($values['changes'])) {
            $this->merge([
                'changes' => $values['changes'],
            ]);
        }

        /*
         * Convert empty optional values to null.
         */
        foreach ([
            'new_taxpayer_id',
            'new_land_area',
            'transfer_area',
            'other_land_area',
            'other_amendment_description',
        ] as $field) {
            if ($this->input($field) === '') {
                $this->merge([
                    $field => null,
                ]);
            }
        }

        /*
         * Trim text fields.
         */
        foreach ([
            'reason',
            'other_amendment_description',
        ] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([
                    $field => trim($value),
                ]);
            }
        }
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            /*
             * Assessment being amended.
             */
            'previous_assessment_id' => [
                'required',
                'uuid',
                'exists:assessments,id',
            ],

            /*
             * Must match the PostgreSQL CHECK constraint.
             */
            'amendment_type' => [
                'required',
                'string',
                Rule::in([
                    'OWNERSHIP_TRANSFER',
                    'LAND_AREA_CHANGE',
                    'PARTIAL_TRANSFER',
                    'LAND_MERGE',
                    'OTHER',
                ]),
            ],

            /*
             * New taxpayer for ownership and partial transfers.
             *
             * Verify that citizens.id is the correct referenced table
             * for your new_taxpayer_id column.
             */
            'new_taxpayer_id' => [
                'nullable',
                'uuid',
                'exists:citizens,id',
                Rule::requiredIf(
                    fn (): bool => in_array(
                        $this->input('amendment_type'),
                        [
                            'OWNERSHIP_TRANSFER',
                            'PARTIAL_TRANSFER',
                        ],
                        true
                    )
                ),
            ],

            /*
             * LAND_AREA_CHANGE.
             */
            'new_land_area' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('amendment_type')
                        === 'LAND_AREA_CHANGE'
                ),
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * PARTIAL_TRANSFER.
             */
            'transfer_area' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('amendment_type')
                        === 'PARTIAL_TRANSFER'
                ),
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * LAND_MERGE.
             *
             * The related parcel/assessment must also be identified
             * and verified in the application workflow.
             */
            'other_land_area' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('amendment_type')
                        === 'LAND_MERGE'
                ),
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * OTHER.
             */
            'other_amendment_description' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('amendment_type') === 'OTHER'
                ),
                'nullable',
                'string',
                'min:5',
                'max:5000',
            ],

            /*
             * Amendment reason.
             */
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:5000',
            ],

            /*
             * Supporting documents.
             *
             * Files are retrieved from Laravel's uploaded-file bag,
             * not from the ordinary input data.
             */
            'supporting_documents' => [
                'nullable',
                'array',
                'max:10',
            ],

            'supporting_documents.*' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png',
            ],

            /*
             * Optional change records.
             */
            'changes' => [
                'sometimes',
                'nullable',
                'array',
                'max:20',
            ],

            'changes.*.field_name' => [
                'required_with:changes',
                'string',
                'max:100',
            ],

            'changes.*.value_type' => [
                'required_with:changes',
                Rule::in([
                    'STRING',
                    'INTEGER',
                    'DECIMAL',
                    'DATE',
                    'DATETIME',
                    'BOOLEAN',
                    'UUID',
                    'JSON',
                ]),
            ],

            'changes.*.old_value' => [
                'nullable',
            ],

            'changes.*.new_value' => [
                'nullable',
            ],

            'changes.*.measurement_unit_id' => [
                'nullable',
                'uuid',
                'exists:measurement_units,id',
            ],

            'changes.*.reason' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'changes.*.change_order' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * Get the validated assessment ID.
     */
    public function assessmentId(): string
    {
        return $this->validated('previous_assessment_id');
    }

    /**
     * Get the validated amendment type.
     */
    public function amendmentType(): string
    {
        return $this->validated('amendment_type');
    }

    /**
     * Get the validated amendment reason.
     */
    public function amendmentReason(): string
    {
        return $this->validated('reason');
    }

    /**
     * Get validated amendment data.
     *
     * Uploaded files are excluded from this array.
     */
    public function amendmentData(): array
    {
        return $this->safe()->only([
            'previous_assessment_id',
            'amendment_type',
            'new_taxpayer_id',
            'new_land_area',
            'transfer_area',
            'other_land_area',
            'other_amendment_description',
            'reason',
            'changes',
        ]);
    }

    /**
     * Get uploaded supporting documents as a consistent array.
     */
    public function supportingDocuments(): array
    {
        /*
         * Preferred key: supporting_documents
         */
        $documents = $this->file('supporting_documents');

        /*
         * Also support common frontend alternatives.
         */
        if ($documents === null) {
            $documents = $this->file('supportingDocuments');
        }

        if ($documents === null) {
            $documents = $this->file('values.supportingDocuments');
        }

        if ($documents instanceof UploadedFile) {
            return [$documents];
        }

        if (! is_array($documents)) {
            return [];
        }

        return array_values(array_filter(
            $documents,
            fn ($file) => $file instanceof UploadedFile
        ));
    }
}
