<?php

namespace App\Http\Requests\PenaltyDiscount;

use Illuminate\Foundation\Http\FormRequest;

class StorePenaltyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()
            ?->can('create', \App\Models\PenaltyDiscountRequest::class)
            ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => [
                'required',
                'uuid',
                'exists:invoices,id',
            ],

            'requested_amount' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,4',
            ],

            'reason' => [
                'required',
                'string',
                'max:5000',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'invoice_id' => 'invoice',
            'requested_amount' => 'requested discount amount',
        ];
    }
}

