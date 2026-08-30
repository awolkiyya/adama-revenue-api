<?php

namespace App\Modules\Revenue\Requests;

use App\Enums\ServiceAction;
use App\Models\ServiceAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rule;

class StoreServiceAccessRuleRequest extends FormRequest
{

    /**
     * Authorization
     */
    public function authorize(): bool
    {
        return auth()->check();
    }



    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Service
            |--------------------------------------------------------------------------
            */
            'service_id' => [
                'required',
                'uuid',
                Rule::exists('revenue_services', 'id'),
            ],



            /*
            |--------------------------------------------------------------------------
            | Sector
            |--------------------------------------------------------------------------
            */
            'sector_id' => [
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
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],



            /*
            |--------------------------------------------------------------------------
            | Actions
            |--------------------------------------------------------------------------
            */
            'actions' => [
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
     * Additional validation
     */
    public function withValidator($validator): void
    {

        $validator->after(function ($validator) {


            $serviceId = $this->service_id;


            if (!$serviceId) {
                return;
            }



            $exists = ServiceAccessRule::query()

                ->where(
                    'service_id',
                    $serviceId
                )

                ->where(
                    'sector_id',
                    $this->sector_id
                )

                ->where(
                    'role_id',
                    $this->role_id
                )

                ->exists();



            if ($exists) {

                $validator->errors()->add(
                    'actions',
                    'This access rule already exists for the selected service, sector and role.'
                );

            }


        });

    }





    /**
     * Custom attributes
     */
    public function attributes(): array
    {
        return [

            'service_id' => 'service',

            'sector_id' => 'sector',

            'role_id' => 'role',

            'actions' => 'service actions',

        ];
    }





    /**
     * Custom messages
     */
    public function messages(): array
    {
        return [

            'service_id.required' =>
                'Service is required.',

            'service_id.exists' =>
                'The selected service does not exist.',



            'sector_id.required' =>
                'Please select a sector.',

            'sector_id.exists' =>
                'The selected sector does not exist.',



            'role_id.required' =>
                'Please select a role.',

            'role_id.exists' =>
                'The selected role does not exist.',



            'actions.required' =>
                'Please select at least one action.',

            'actions.array' =>
                'Actions must be an array.',

            'actions.min' =>
                'Please select at least one action.',



            'is_active.boolean' =>
                'Active status must be true or false.',

        ];
    }





    /**
     * Prepare request data
     */
    protected function prepareForValidation(): void
    {


        /*
        |--------------------------------------------------------------------------
        | Default active status
        |--------------------------------------------------------------------------
        */

        if (!$this->has('is_active')) {

            $this->merge([
                'is_active' => true,
            ]);

        }





        /*
        |--------------------------------------------------------------------------
        | Remove duplicate actions
        |--------------------------------------------------------------------------
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





        /*
        |--------------------------------------------------------------------------
        | Inject service_id from route
        |--------------------------------------------------------------------------
        |
        | Route:
        | /services/{service}/access-rules
        |
        | {service} is UUID string
        |
        */

        $serviceId = $this->route('service');


        if ($serviceId) {

            $this->merge([

                'service_id' => $serviceId,

            ]);

        }


    }

}