<?php

namespace App\Modules\Dashboard\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator as LaravelValidator;

class DashboardSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication and permission authorization are enforced
        // by the dashboard API route middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => [
                'sometimes',
                'required_with:to',
                'date_format:Y-m-d',
            ],

            'to' => [
                'sometimes',
                'required_with:from',
                'date_format:Y-m-d',
                'after_or_equal:from',
            ],
        ];
    }

    /**
     * Add cross-field validation for the maximum date range.
     */
    public function withValidator(LaravelValidator $validator): void
    {
        $validator->after(function (LaravelValidator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->input('from');
            $to = $this->input('to');

            // Both omitted means the service selects the default period.
            if ($from === null && $to === null) {
                return;
            }

            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);

            if ($start === false || $end === false) {
                return;
            }

            if ($start->diffInDays($end) > 365) {
                $validator->errors()->add(
                    'to',
                    'The reporting period cannot exceed 366 calendar days.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required_with' => 'The from date is required when to is provided.',
            'to.required_with' => 'The to date is required when from is provided.',
            'from.date_format' => 'The from date must use YYYY-MM-DD format.',
            'to.date_format' => 'The to date must use YYYY-MM-DD format.',
            'to.after_or_equal' => 'The to date must be on or after the from date.',
        ];
    }
}
