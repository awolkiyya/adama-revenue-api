<?php

namespace App\Http\Requests\LeaseAmendment;

use Illuminate\Foundation\Http\FormRequest;

class RejectLeaseAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('lease-amendments.reject') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:5000',
            ],
        ];
    }
}