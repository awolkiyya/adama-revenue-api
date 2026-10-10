<?php

namespace App\Modules\PenaltyDiscount\Requests;

use App\Models\PenaltyDiscountRequest as PenaltyDiscountRequestModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePenaltyDiscountRequest extends FormRequest
{
    /**
     * Authorize updating a penalty discount request.
     */
    public function authorize(): bool
    {
        $penaltyDiscountRequest = $this->route(
            'penaltyDiscountRequest'
        );

        return $penaltyDiscountRequest instanceof PenaltyDiscountRequestModel
            && $this->user()?->can(
                'update',
                $penaltyDiscountRequest
            ) === true;
    }

    /**
     * Validation rules for updating a penalty discount request.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id' => [
                'required',
                'uuid',
                Rule::exists('invoices', 'id'),
            ],

            /*
            |--------------------------------------------------------------------------
            | Requested Discount Amount
            |--------------------------------------------------------------------------
            */

            'requested_amount' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
            ],

            /*
            |--------------------------------------------------------------------------
            | Reason
            |--------------------------------------------------------------------------
            */

            'reason' => [
                'required',
                'string',
                'max:5000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Supporting Document
            |--------------------------------------------------------------------------
            |
            | Optional when updating. If no new file is uploaded, the
            | existing supporting document should remain unchanged.
            |
            */

            'supporting_file' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_id.required' =>
                'Please select an invoice.',

            'invoice_id.uuid' =>
                'The selected invoice ID is invalid.',

            'invoice_id.exists' =>
                'The selected invoice does not exist.',

            /*
            |--------------------------------------------------------------------------
            | Requested Discount Amount
            |--------------------------------------------------------------------------
            */

            'requested_amount.required' =>
                'Please enter the requested discount amount.',

            'requested_amount.numeric' =>
                'The requested discount amount must be a valid number.',

            'requested_amount.gt' =>
                'The requested discount amount must be greater than zero.',

            'requested_amount.decimal' =>
                'The requested discount amount may have up to four decimal places.',

            /*
            |--------------------------------------------------------------------------
            | Reason
            |--------------------------------------------------------------------------
            */

            'reason.required' =>
                'Please provide a reason for the discount request.',

            'reason.string' =>
                'The reason must be valid text.',

            'reason.max' =>
                'The reason cannot exceed 5,000 characters.',

            /*
            |--------------------------------------------------------------------------
            | Supporting Document
            |--------------------------------------------------------------------------
            */

            'supporting_file.file' =>
                'The supporting document must be a valid uploaded file.',

            'supporting_file.mimes' =>
                'The supporting document must be a PDF, JPG, JPEG, or PNG file.',

            'supporting_file.max' =>
                'The supporting document must not exceed 5 MB.',
        ];
    }

    /**
     * Human-readable attribute names for validation errors.
     */
    public function attributes(): array
    {
        return [
            'invoice_id' => 'invoice',
            'requested_amount' => 'requested discount amount',
            'reason' => 'reason',
            'supporting_file' => 'supporting document',
        ];
    }
}
