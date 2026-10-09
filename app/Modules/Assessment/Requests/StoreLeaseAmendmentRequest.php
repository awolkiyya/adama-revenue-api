<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaseAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('lease-amendments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'previous_assessment_id' => [
                'required',
                'uuid',
                'exists:assessments,id',
            ],

            'amendment_type' => [
                'required',
                Rule::in([
                    'LAND_AREA_CHANGE',
                    'NAME_TRANSFER',
                    'PARTIAL_TRANSFER',
                    'MERGE',
                ]),
            ],

            'new_taxpayer_id' => [
                'nullable',
                'uuid',
                'exists:taxpayers,id',
            ],

            /*
             * LAND_AREA_CHANGE
             */
            'new_land_area' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * PARTIAL_TRANSFER
             */
            'transfer_area' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * MERGE
             *
             * This is manually entered.
             * It is NOT another assessment.
             */
            'other_land_area' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'reason' => [
                'required',
                'string',
                'min:5',
                'max:5000',
            ],

            'supporting_documents' => [
                'nullable',
                'array',
                'max:10',
            ],

            'supporting_documents.*' => [
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png',
            ],

            /*
             * Optional change records sent by the frontend.
             *
             * The service still validates the business meaning
             * rather than trusting these values blindly.
             */
            'changes' => [
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
}