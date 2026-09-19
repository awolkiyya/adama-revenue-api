<?php

namespace App\Modules\Revenue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRevenueCodePaymentScheduleRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'payment_schedule_rules.create'
        ) ?? false;
    }

    public function rules(): array
    {
        return [
            'revenue_code_id' => [
                'required',
                'uuid',
                Rule::exists('revenue_codes', 'id')
                    ->where(function ($query): void {
                        $query->whereNull('deleted_at');
                    }),
                Rule::unique(
                    'revenue_code_payment_schedule_rules',
                    'revenue_code_id'
                ),
            ],

            'is_enabled' => [
                'required',
                'boolean',
            ],

            /*
             * Optional.
             *
             * If supplied:
             *   > 0
             *   <= 100
             */
            'first_installment_percentage' => [
                'nullable',
                'numeric',
                'gt:0',
                'lte:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'revenue_code_id.required' =>
                'Revenue code is required.',

            'revenue_code_id.uuid' =>
                'Revenue code must be a valid UUID.',

            'revenue_code_id.exists' =>
                'The selected revenue code does not exist.',

            'revenue_code_id.unique' =>
                'A payment schedule rule already exists for this revenue code.',

            'is_enabled.required' =>
                'The payment scheduling status is required.',

            'is_enabled.boolean' =>
                'The payment scheduling status must be true or false.',

            'first_installment_percentage.numeric' =>
                'First installment percentage must be a number.',

            'first_installment_percentage.gt' =>
                'First installment percentage must be greater than 0.',

            'first_installment_percentage.lte' =>
                'First installment percentage cannot be greater than 100.',
        ];
    }
}