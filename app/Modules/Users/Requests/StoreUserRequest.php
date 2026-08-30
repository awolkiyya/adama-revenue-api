<?php

namespace App\Modules\Users\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | BASIC INFORMATION
            |--------------------------------------------------------------------------
            */

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],

            /*
            |--------------------------------------------------------------------------
            | PASSWORD
            |--------------------------------------------------------------------------
            |
            | Password must:
            | - contain at least 12 characters
            | - contain at least one letter
            | - contain at least one number
            | - contain at least one symbol
            |
            */

            'password' => [
                'required',
                'string',
                Password::min(12)
                    ->letters()
                    ->numbers()
                    ->symbols(),
            ],

            /*
            |--------------------------------------------------------------------------
            | ROLE
            |--------------------------------------------------------------------------
            |
            | Frontend sends:
            |
            | role_id: 1
            |
            | The role must:
            | - be an integer
            | - exist in the roles table
            | - belong to the api guard
            |
            */

            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')
                    ->where(function ($query) {
                        $query->where('guard_name', 'api');
                    }),
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
            | ORGANIZATIONAL SCOPE
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
            |
            | Sector requirement should be determined by the backend based
            | on the selected role.
            |
            */

            'sector_id' => [
                'nullable',
                'uuid',
                'exists:sectors,id',
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
        ];
    }

    /**
     * Get the selected API role.
     */
    public function role(): ?Role
    {
        if (!$this->filled('role_id')) {
            return null;
        }

        return Role::query()
            ->where('id', $this->integer('role_id'))
            ->where('guard_name', 'api')
            ->first();
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */

            'name.required' =>
                'Full name is required.',

            'name.max' =>
                'Full name cannot exceed 255 characters.',

            'email.required' =>
                'Email address is required.',

            'email.email' =>
                'Please enter a valid email address.',

            'email.unique' =>
                'This email address is already registered.',

            'phone.max' =>
                'Phone number cannot exceed 20 characters.',

            /*
            |--------------------------------------------------------------------------
            | Password
            |--------------------------------------------------------------------------
            */

            'password.required' =>
                'Password is required.',

            /*
            |--------------------------------------------------------------------------
            | Role
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
            | Administrative Level
            |--------------------------------------------------------------------------
            */

            'level.required' =>
                'Administrative level is required.',

            'level.in' =>
                'The selected administrative level is invalid.',

            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
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
            | Sector
            |--------------------------------------------------------------------------
            */

            'sector_id.uuid' =>
                'The selected sector is invalid.',

            'sector_id.exists' =>
                'The selected sector does not exist.',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active.boolean' =>
                'The active status must be true or false.',

            /*
            |--------------------------------------------------------------------------
            | Avatar
            |--------------------------------------------------------------------------
            */

            'avatar.image' =>
                'The avatar must be a valid image.',

            'avatar.mimes' =>
                'The avatar must be a JPG, JPEG, PNG, or WEBP image.',

            'avatar.max' =>
                'The avatar image must not be larger than 2 MB.',
        ];
    }

    /**
     * Custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'role_id' => 'role',
            'administrative_unit_id' => 'administrative unit',
            'sector_id' => 'sector',
            'is_active' => 'active status',
        ];
    }
}
