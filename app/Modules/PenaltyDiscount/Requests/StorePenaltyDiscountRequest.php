<?php

namespace App\Modules\PenaltyDiscount\Requests;

use App\Models\PenaltyDiscountRequest;
use Illuminate\Foundation\Http\FormRequest;

class StorePenaltyDiscountRequest extends FormRequest
{
    /**
     * Authorize creation of a penalty discount request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            PenaltyDiscountRequest::class
        ) ?? false;
    }

    /**
     * Validation rules for creating a penalty discount request.
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
                'exists:invoices,id',
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
            | Optional supporting document for the penalty discount request.
            | The file is stored and associated with the request by the
            | application service/controller, not by this FormRequest.
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
     * Human-readable attribute names for validation errors.
     */
    public function attributes(): array
    {
        return [
            'invoice_id' => 'invoice',
            'requested_amount' => 'requested discount amount',
            'reason' => 'reason for the discount',
            'supporting_file' => 'supporting document',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'supporting_file.file' =>
                'The supporting document must be a valid uploaded file.',

            'supporting_file.mimes' =>
                'The supporting document must be a PDF, JPG, JPEG, or PNG file.',

            'supporting_file.max' =>
                'The supporting document must not exceed 5 MB.',

            'requested_amount.gt' =>
                'The requested discount amount must be greater than zero.',

            'requested_amount.decimal' =>
                'The requested discount amount may have up to four decimal places.',
        ];
    }
}
