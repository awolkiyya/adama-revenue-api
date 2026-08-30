<?php

namespace App\Modules\Citizens\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CitizenResource extends JsonResource
{
    /**
     * Transform resource into array.
     */
    public function toArray(Request $request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'citizen_uid' => $this->citizen_uid,


            /*
            |--------------------------------------------------------------------------
            | Identity Information
            |--------------------------------------------------------------------------
            */

            'full_name' => $this->full_name,

            'national_id' => $this->national_id,

            'phone' => $this->phone,

            'gender' => $this->gender,

            'date_of_birth' => $this->date_of_birth,

            "email" => $this->email,



            /*
            |--------------------------------------------------------------------------
            | Address Information
            |--------------------------------------------------------------------------
            */

            'address' => $this->address,

            


            'administrative_unit' => $this->whenLoaded(
                'administrativeUnit',
                function () {

                    return [

                        'id' => $this->administrativeUnit->id,

                        'name' => $this->administrativeUnit->name,

                        'level' => $this->administrativeUnit->level,


                        /*
                        | Example:
                        | Wereda 01,
                        | Abbaa Gadaa Subcity,
                        | Adama City
                        */

                        'full_address' =>$this->address,

                    ];

                }
            ),



            /*
            |--------------------------------------------------------------------------
            | Data Source
            |--------------------------------------------------------------------------
            */

            'source' => $this->source,


            'external_id' => $this->when(
                $this->source === 'EXTERNAL_SYSTEM',
                $this->external_id
            ),



            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'status' => $this->is_active
            ? 'ACTIVE'
            : 'INACTIVE',


            /*
            |--------------------------------------------------------------------------
            | Audit Information
            |--------------------------------------------------------------------------
            */

            'created_by' => $this->whenLoaded(
                'createdBy',
                function () {

                    return [

                        'id' => $this->createdBy->id,

                        'name' => $this->createdBy->name,

                    ];

                }
            ),



            'updated_by' => $this->whenLoaded(
                'updatedBy',
                function () {

                    return [

                        'id' => $this->updatedBy->id,

                        'name' => $this->updatedBy->name,

                    ];

                }
            ),



            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'registered_at' => $this->registered_at,

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,

        ];
    }
}