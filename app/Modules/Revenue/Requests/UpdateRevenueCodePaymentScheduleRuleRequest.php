<?php

namespace App\Modules\Revenue\Requests;

use App\Models\RevenueCodePaymentScheduleRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRevenueCodePaymentScheduleRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'payment_schedule_rules.update'
        ) ?? false;
    }

    public function rules(): array
    {
        /** @var RevenueCodePaymentScheduleRule|null $rule */
        $rule = $this->route('paymentScheduleRule');

        return [
            /*
             * Revenue code is intentionally immutable after creation.
             *
             * The frontend also disables this field in edit mode.
             */
            'revenue_code_id' => [
                'sometimes',
                'uuid',
                Rule::exists('revenue_codes', 'id')
                    ->where(function ($query): void {
                        $query->whereNull('deleted_at');
                    }),
                Rule::unique(
                    'revenue_code_payment_schedule_rules',
                    'revenue_code_id'
                )->ignore($rule?->id),
            ],

            'is_enabled' => [
                'sometimes',
                'boolean',
            ],

            /*
             * Optional.
             *
             * Sending null clears the configured percentage.
             */
            'first_installment_percentage' => [
                'sometimes',
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
            'revenue_code_id.uuid' =>
                'Revenue code must be a valid UUID.',

            'revenue_code_id.exists' =>
                'The selected revenue code does not exist.',

            'revenue_code_id.unique' =>
                'A payment schedule rule already exists for this revenue code.',

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