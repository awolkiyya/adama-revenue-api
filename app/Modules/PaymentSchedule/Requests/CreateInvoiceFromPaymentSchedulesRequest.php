<?php

namespace App\Modules\PaymentSchedule\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceFromPaymentSchedulesRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'payment_schedule_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'payment_schedule_ids.*' => [
                'required',
                'string',
                'distinct',
                'uuid',
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'payment_schedule_ids.required' =>
                'At least one payment schedule must be selected.',

            'payment_schedule_ids.array' =>
                'Payment schedule IDs must be provided as an array.',

            'payment_schedule_ids.min' =>
                'At least one payment schedule must be selected.',

            'payment_schedule_ids.*.required' =>
                'Each payment schedule ID is required.',

            'payment_schedule_ids.*.uuid' =>
                'Each payment schedule ID must be a valid UUID.',

            'payment_schedule_ids.*.distinct' =>
                'A payment schedule cannot be selected more than once.',
        ];
    }

    /**
     * Get normalized payment schedule IDs.
     *
     * @return array<int, string>
     */
    public function paymentScheduleIds(): array
    {
        return array_values(
            array_unique(
                $this->validated('payment_schedule_ids', [])
            )
        );
    }
}