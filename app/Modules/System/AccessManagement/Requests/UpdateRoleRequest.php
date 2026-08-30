<?php

namespace App\Modules\System\AccessManagement\Requests;

use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Authorization is handled by RolePolicy in the controller.
     *
     * Example:
     *
     *     $this->authorize('update', $role);
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Role|null $role */
        $role = $this->route('role');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',

                Rule::unique('roles', 'name')
                    ->where('guard_name', 'api')
                    ->whereNull('deleted_at')
                    ->ignore($role?->id),
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
                'string',
                Rule::exists('permissions', 'name')
                    ->where('guard_name', 'api'),
            ],
        ];
    }

    /**
     * System roles are protected from renaming.
     *
     * Additional protection should also exist in the
     * RolePolicy/service layer so this rule cannot be
     * bypassed outside this request.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Role|null $role */
            $role = $this->route('role');

            if (
                $role?->isSystem()
                && $this->has('name')
                && $this->input('name') !== $role->name
            ) {
                $validator->errors()->add(
                    'name',
                    'System roles cannot be renamed.'
                );
            }
        });
    }
}