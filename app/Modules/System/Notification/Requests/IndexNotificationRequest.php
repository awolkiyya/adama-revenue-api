<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNotificationRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'filter' => [
                'sometimes',
                'string',
                Rule::in(['all', 'read', 'unread']),
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * Get validated query parameters.
     *
     * @return array{
     *     filter?: string,
     *     per_page?: int,
     *     page?: int
     * }
     */
    public function filters(): array
    {
        return $this->validated();
    }
}
