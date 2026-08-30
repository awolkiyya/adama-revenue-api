<?php

namespace App\Modules\Assessment\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class UpdateAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [

            'taxpayerId' => [
                'sometimes',
                'required',
                'string',
                'exists:citizens,id',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'DRAFT',
                    'PENDING_APPROVAL',
                ]),
            ],

            'services' => [
                'sometimes',
                'required',
                'array',
                'min:1',
            ],

            'services.*.serviceId' => [
                'required',
                'string',
                'exists:revenue_services,id',
            ],

            'services.*.serviceCode' => [
                'required',
                'string',
                'max:100',
            ],

            'services.*.fields' => [
                'required',
                'array',
            ],

            'files.*' => [
                'nullable',
                'file',
                'max:10240',
            ],
        ];
    }


    public function prepareForValidation(): void
    {
        if (
            is_string(
                $this->input('services')
            )
        ) {

            $decoded =
                json_decode(
                    $this->input('services'),
                    true
                );

            if (
                json_last_error() ===
                JSON_ERROR_NONE
            ) {

                $this->merge([
                    'services' =>
                        $decoded,
                ]);
            }
        }
    }
}