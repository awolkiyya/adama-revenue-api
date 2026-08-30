<?php

namespace App\Modules\Users\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        /*
        |--------------------------------------------------------------------------
        | ROUTE USER
        |--------------------------------------------------------------------------
        |
        | Get the actual user being edited from route model binding.
        |
        */

        $user = $this->route('user');

        /*
        |--------------------------------------------------------------------------
        | If route binding returns the User model
        |--------------------------------------------------------------------------
        */

        $userId = $user?->id;

        /*
        |--------------------------------------------------------------------------
        | Rules
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | BASIC INFORMATION
            |--------------------------------------------------------------------------
            */

            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                |
                | Ignore the current user's own email.
                |
                | Example:
                |
                | Current user:
                | ID    = abc
                | email = awol@example.com
                |
                | Updating to:
                | awol@example.com
                |
                | This is allowed.
                |
                | But if another user has:
                | awol@example.com
                |
                | validation fails.
                |
                */

                Rule::unique(
                    'users',
                    'email'
                )->ignore($userId),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],

            /*
            |--------------------------------------------------------------------------
            | ROLE
            |--------------------------------------------------------------------------
            */

            'role_id' => [
                'required',
                'integer',

                Rule::exists(
                    'roles',
                    'id'
                )->where(
                    function ($query) {
                        $query->where(
                            'guard_name',
                            'api'
                        );
                    }
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | LEVEL
            |--------------------------------------------------------------------------
            */

            'level' => [
                'required',
                Rule::in([
                    'CITY',
                    'SUBCITY',
                    'WEREDA',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | ADMINISTRATIVE UNIT
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'required',
                'uuid',
                'exists:administrative_units,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | SECTOR
            |--------------------------------------------------------------------------
            */

            'sector_id' => [
                'nullable',
                'uuid',
                'exists:sectors,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | AVATAR
            |--------------------------------------------------------------------------
            */

            'avatar' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | GET SELECTED ROLE
    |--------------------------------------------------------------------------
    */

    public function role(): ?Role
    {
        if (!$this->filled('role_id')) {
            return null;
        }

        return Role::query()
            ->where(
                'id',
                $this->integer('role_id')
            )
            ->where(
                'guard_name',
                'api'
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | ROLE REQUIRES SECTOR
    |--------------------------------------------------------------------------
    */

    public function roleRequiresSector(): bool
    {
        $role = $this->role();

        if (!$role) {
            return false;
        }

        return in_array(
            $role->name,
            [
                'SECTOR_OFFICER',
                'REVENUE_DECISION_OFFICER',
                'REVENUE_COLLECTOR',
            ],
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOM VALIDATION MESSAGES
    |--------------------------------------------------------------------------
    */

    public function messages(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | BASIC INFORMATION
            |--------------------------------------------------------------------------
            */

            'name.string' =>
                'Name must be a valid text value.',

            'name.max' =>
                'Name cannot exceed 255 characters.',

            'email.email' =>
                'Please enter a valid email address.',

            'email.unique' =>
                'This email address is already registered by another user.',

            'phone.max' =>
                'Phone number cannot exceed 20 characters.',

            /*
            |--------------------------------------------------------------------------
            | ROLE
            |--------------------------------------------------------------------------
            */

            'role_id.required' =>
                'Please select a role.',

            'role_id.integer' =>
                'The selected role is invalid.',

            'role_id.exists' =>
                'The selected role does not exist or is not available.',

            /*
            |--------------------------------------------------------------------------
            | LEVEL
            |--------------------------------------------------------------------------
            */

            'level.required' =>
                'Administrative level is required.',

            'level.in' =>
                'The selected administrative level is invalid.',

            /*
            |--------------------------------------------------------------------------
            | ADMINISTRATIVE UNIT
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id.required' =>
                'Administrative unit is required.',

            'administrative_unit_id.uuid' =>
                'The selected administrative unit is invalid.',

            'administrative_unit_id.exists' =>
                'The selected administrative unit does not exist.',

            /*
            |--------------------------------------------------------------------------
            | SECTOR
            |--------------------------------------------------------------------------
            */

            'sector_id.uuid' =>
                'The selected sector is invalid.',

            'sector_id.exists' =>
                'The selected sector does not exist.',

            /*
            |--------------------------------------------------------------------------
            | AVATAR
            |--------------------------------------------------------------------------
            */

            'avatar.image' =>
                'The avatar must be a valid image.',

            'avatar.mimes' =>
                'The avatar must be a JPG, JPEG, PNG, or WEBP image.',

            'avatar.max' =>
                'The avatar image must not exceed 2 MB.',

            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            'is_active.boolean' =>
                'Active status must be true or false.',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOM ATTRIBUTE NAMES
    |--------------------------------------------------------------------------
    */

    public function attributes(): array
    {
        return [
            'role_id' =>
                'role',

            'administrative_unit_id' =>
                'administrative unit',

            'sector_id' =>
                'sector',

            'is_active' =>
                'active status',
        ];
    }
}