<?php

namespace App\Modules\PenaltyDiscountRequests;

use App\Models\PenaltyDiscountRequest as PenaltyDiscountRequestModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecidePenaltyDiscountRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user can decide this request.
     */
    public function authorize(): bool
    {
        $penaltyDiscountRequest = $this->route('penaltyDiscountRequest');

        return $penaltyDiscountRequest instanceof PenaltyDiscountRequestModel
            && $this->user()?->can('decide', $penaltyDiscountRequest) === true;
    }

    /**
     * Get the validation rules for the decision.
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    PenaltyDiscountRequestModel::DECISION_APPROVED,
                    PenaltyDiscountRequestModel::DECISION_REJECTED,
                ]),
            ],

            'approved_amount' => [
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,4',
                'required_if:decision,' . PenaltyDiscountRequestModel::DECISION_APPROVED,
            ],

            'decision_reason' => [
                'nullable',
                'string',
                'max:5000',
                'required_if:decision,' . PenaltyDiscountRequestModel::DECISION_REJECTED,
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Please select whether to approve or reject this request.',
            'decision.in' => 'The decision must be either APPROVED or REJECTED.',

            'approved_amount.required_if' => 'The approved discount amount is required when approving a request.',
            'approved_amount.numeric' => 'The approved discount amount must be a valid number.',
            'approved_amount.gt' => 'The approved discount amount must be greater than zero.',
            'approved_amount.decimal' => 'The approved discount amount may have up to four decimal places.',

            'decision_reason.required_if' => 'A decision reason is required when rejecting a request.',
            'decision_reason.string' => 'The decision reason must be valid text.',
            'decision_reason.max' => 'The decision reason cannot exceed 5,000 characters.',
        ];
    }

    /**
     * Get human-readable attribute names.
     */
    public function attributes(): array
    {
        return [
            'decision' => 'decision',
            'approved_amount' => 'approved discount amount',
            'decision_reason' => 'decision reason',
        ];
    }
}