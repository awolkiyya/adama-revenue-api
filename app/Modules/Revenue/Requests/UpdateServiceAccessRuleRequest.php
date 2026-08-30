<?php

namespace App\Modules\Revenue\Requests;

use App\Enums\ServiceAction;
use App\Models\ServiceAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rule;

class UpdateServiceAccessRuleRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }


    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Sector
            |--------------------------------------------------------------------------
            */
            'sector_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('sectors', 'id'),
            ],



            /*
            |--------------------------------------------------------------------------
            | Role
            |--------------------------------------------------------------------------
            */
            'role_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('roles', 'id'),
            ],



            /*
            |--------------------------------------------------------------------------
            | Actions
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | [
            |   "CREATE",
            |   "ASSESS",
            |   "APPROVE"
            | ]
            |
            */
            'actions' => [
                'sometimes',
                'required',
                'array',
                'min:1',
            ],


            'actions.*' => [
                'required',
                new Enum(ServiceAction::class),
            ],



            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */
            'is_active' => [
                'sometimes',
                'boolean',
            ],

        ];
    }



    /**
     * Check duplicate service + sector + role.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {


            /** @var ServiceAccessRule $rule */
            $rule = $this->route('rule');


            $serviceId = $this->route('service')->id;


            $sectorId = $this->input(
                'sector_id',
                $rule->sector_id
            );


            $roleId = $this->input(
                'role_id',
                $rule->role_id
            );



            $exists = ServiceAccessRule::query()
                ->where('service_id', $serviceId)
                ->where('sector_id', $sectorId)
                ->where('role_id', $roleId)
                ->where('id', '!=', $rule->id)
                ->exists();



            if ($exists) {

                $validator->errors()->add(
                    'sector_id',
                    'An access rule already exists for this service, sector and role.'
                );

            }

        });
    }



    /**
     * Friendly attribute names.
     */
    public function attributes(): array
    {
        return [

            'sector_id' => 'sector',

            'role_id' => 'role',

            'actions' => 'service actions',

            'is_active' => 'status',

        ];
    }



    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [

            'sector_id.exists' =>
                'The selected sector does not exist.',


            'role_id.exists' =>
                'The selected role does not exist.',


            'actions.required' =>
                'Please select at least one service action.',


            'actions.array' =>
                'Actions must be an array.',


            'actions.min' =>
                'Please select at least one service action.',


            'sector_id.uuid' =>
                'Sector must be a valid UUID.',


            'role_id.uuid' =>
                'Role must be a valid UUID.',


            'is_active.boolean' =>
                'Status must be true or false.',

        ];
    }



    /**
     * Prepare request data.
     */
    protected function prepareForValidation(): void
    {

        /**
         * Remove duplicated actions
         */
        if ($this->has('actions')) {

            $this->merge([
                'actions' => array_values(
                    array_unique(
                        $this->actions
                    )
                ),
            ]);

        }

    }
}