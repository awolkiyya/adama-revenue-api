<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

class ApplyLeaseAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('lease-amendments.apply') ?? false;
    }

    public function rules(): array
    {
        return [
            /*
             * The revenue officer creates the independent
             * replacement assessment first, then supplies
             * its ID here.
             */
            'new_assessment_id' => [
                'required',
                'uuid',
                'exists:assessments,id',
            ],
        ];
    }
}