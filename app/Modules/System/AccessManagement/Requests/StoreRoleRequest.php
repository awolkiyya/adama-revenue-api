<?php

namespace App\Modules\System\AccessManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')
                    ->where('guard_name', 'api')
                    ->whereNull('deleted_at'),
            ],

            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'permissions' => [
                'sometimes',
                'array',
            ],

            'permissions.*' => [
                Rule::exists('permissions', 'name')
                    ->where('guard_name', 'api'),
            ],
        ];
    }
}