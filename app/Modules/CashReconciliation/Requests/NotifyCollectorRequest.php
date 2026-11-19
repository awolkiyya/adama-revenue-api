<?php

declare(strict_types=1);

namespace App\Modules\CashReconciliation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NotifyCollectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'collector_id' => [
                'required',
                'uuid',
                'exists:users,id',
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'collector_id.required' => 'A cash collector must be selected.',
            'collector_id.uuid' => 'The selected collector ID is invalid.',
            'collector_id.exists' => 'The selected cash collector does not exist.',
            'date_from.date' => 'The start date must be a valid date.',
            'date_to.date' => 'The end date must be a valid date.',
            'date_to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
