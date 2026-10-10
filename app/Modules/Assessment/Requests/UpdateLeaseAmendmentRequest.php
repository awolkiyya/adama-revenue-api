<?php

namespace App\Modules\Assessment\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaseAmendmentRequest extends FormRequest
{
    /**
     * Authorize the request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize JSON and multipart frontend payloads.
     *
     * Supported input formats:
     * - JSON request
     * - FormData with a JSON "values" field
     * - Direct snake_case request fields
     */
    protected function prepareForValidation(): void
    {
        $values = $this->input('values');

        if (is_string($values)) {
            $decoded = json_decode($values, true);

            if (
                json_last_error() === JSON_ERROR_NONE &&
                is_array($decoded)
            ) {
                $values = $decoded;
            }
        }

        if (! is_array($values)) {
            $values = [];
        }

        $mapping = [
            'amendment_type' => 'amendmentType',
            'new_taxpayer_id' => 'newTaxpayerId',
            'new_land_area' => 'newLandArea',
            'transfer_area' => 'transferArea',
            'other_land_area' => 'mergedLandArea',
            'other_amendment_description' => 'otherAmendmentDescription',
            'reason' => 'reason',
        ];

        $normalized = [];

        foreach ($mapping as $target => $source) {
            $value = $values[$source]
                ?? $this->input($target);

            if ($value === '') {
                $value = null;
            }

            if ($this->exists($target) || array_key_exists($source, $values)) {
                $normalized[$target] = $value;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }

        /*
         * Support documents sent using either:
         * - supporting_documents
         * - supporting_documents[]
         * - values.supportingDocuments
         *
         * Uploaded files are read from the request file bag.
         */
        $documents = $this->file('supporting_documents');

        if ($documents === null) {
            $documents = $this->file('values.supportingDocuments');
        }

        if ($documents !== null) {
            $this->merge([
                'supporting_documents' => $documents,
            ]);
        }
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        $type = $this->input('amendment_type');

        return [
            /*
             * These values must match the PostgreSQL CHECK constraint.
             */
            'amendment_type' => [
                'sometimes',
                'required',
                Rule::in([
                    'OWNERSHIP_TRANSFER',
                    'LAND_AREA_CHANGE',
                    'PARTIAL_TRANSFER',
                    'LAND_MERGE',
                    'OTHER',
                ]),
            ],

            /*
             * New taxpayer for ownership transfer.
             *
             * A different taxpayer is required for an ownership transfer.
             * For partial transfers, the receiving taxpayer is also required.
             */
            'new_taxpayer_id' => [
                Rule::requiredIf(
                    fn (): bool => in_array(
                        $type,
                        ['OWNERSHIP_TRANSFER', 'PARTIAL_TRANSFER'],
                        true
                    )
                ),
                'nullable',
                'uuid',
                'exists:citizens,id',
            ],

            /*
             * Registered land area change.
             */
            'new_land_area' => [
                Rule::requiredIf(
                    fn (): bool => $type === 'LAND_AREA_CHANGE'
                ),
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * Partial transfer area.
             *
             * The controller/service should additionally verify that this
             * area is smaller than the source assessment's registered area.
             */
            'transfer_area' => [
                Rule::requiredIf(
                    fn (): bool => $type === 'PARTIAL_TRANSFER'
                ),
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * Additional land area for a merge.
             *
             * This is an area, not an assessment identifier. A complete merge
             * workflow should identify and verify the other land parcel too.
             */
            'other_land_area' => [
                Rule::requiredIf(
                    fn (): bool => $type === 'LAND_MERGE'
                ),
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * Required description for OTHER amendments.
             */
            'other_amendment_description' => [
                Rule::requiredIf(
                    fn (): bool => $type === 'OTHER'
                ),
                'nullable',
                'string',
                'min:5',
                'max:5000',
            ],

            /*
             * Reason for the amendment.
             */
            'reason' => [
                'sometimes',
                'required',
                'string',
                'min:5',
                'max:5000',
            ],

            /*
             * Supporting documents.
             */
            'supporting_documents' => [
                'sometimes',
                'nullable',
                'array',
                'max:5',
            ],

            'supporting_documents.*' => [
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png',
            ],
        ];
    }

    /**
     * Return only validated amendment fields.
     */
    public function amendmentData(): array
    {
        return $this->safe()->only([
            'amendment_type',
            'new_taxpayer_id',
            'new_land_area',
            'transfer_area',
            'other_land_area',
            'other_amendment_description',
            'reason',
        ]);
    }

    /**
     * Return uploaded supporting documents.
     *
     * @return array<int, \Illuminate\Http\UploadedFile>
     */
    public function supportingDocuments(): array
    {
        $documents = $this->file('supporting_documents', []);

        if ($documents instanceof \Illuminate\Http\UploadedFile) {
            return [$documents];
        }

        return is_array($documents) ? $documents : [];
    }
}

