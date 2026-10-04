<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $paymentProviderId = $this->route('paymentProvider')?->id
            ?? $this->route('paymentProvider');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('payment_providers', 'code')
                    ->ignore($paymentProviderId),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'fee_percentage' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:100',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper(trim($this->code)),
            ]);
        }
    }
}