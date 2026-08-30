<?php

namespace App\Modules\Assessment\Requests;

use Illuminate\Foundation\Http\FormRequest;


class CancelAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}