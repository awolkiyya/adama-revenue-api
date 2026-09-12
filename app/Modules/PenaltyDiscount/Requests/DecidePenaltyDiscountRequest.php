<?php

namespace App\Http\Requests\PenaltyDiscount;

use App\Models\PenaltyDiscountRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecidePenaltyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('penaltyDiscountRequest');

        return $request instanceof PenaltyDiscountRequest
            && (
                $this->user()?->can('decide', $request)
                ?? false
            );
    }

    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    PenaltyDiscountRequest::DECISION_APPROVED,
                    PenaltyDiscountRequest::DECISION_REJECTED,
                ]),
            ],

            'approved_amount' => [
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,4',
                'required_if:decision,APPROVED',
            ],

            'decision_reason' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:decision,REJECTED',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'approved_amount' => 'approved discount amount',
            'decision_reason' => 'decision reason',
        ];
    }
}
