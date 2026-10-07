<?php

namespace App\Http\Requests\LeaseAmendment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaseAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('lease-amendments.update') ?? false;
    }

    public function rules(): array
    {
        return [
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

            'new_land_area' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'transfer_area' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            /*
             * Manually entered Other Land.
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
        ];
    }
}