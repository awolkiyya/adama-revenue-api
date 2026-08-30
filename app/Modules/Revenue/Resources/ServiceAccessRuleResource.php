<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class ServiceAccessRuleResource extends JsonResource
{

    public function toArray(Request $request): array
    {

        return [

            'id' => (string) $this->id,


            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */
            'service' => [

                'id' => $this->service?->id,

                'name' => $this->service?->name,

            ],



            /*
            |--------------------------------------------------------------------------
            | Sector
            |--------------------------------------------------------------------------
            */
            'sector' => [

                'id' => $this->sector?->id,

                'name' => $this->sector?->name,

            ],



            /*
            |--------------------------------------------------------------------------
            | Role
            |--------------------------------------------------------------------------
            */
            'role' => [

                'id' => $this->role?->id,

                'name' => $this->role?->name,

            ],



            /*
            |--------------------------------------------------------------------------
            | Allowed Actions
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | [
            |   "CREATE",
            |   "UPDATE",
            |   "APPROVE"
            | ]
            |
            */
            'actions' => $this->actions ?? [],



            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */
            'isActive' => (bool) $this->is_active,



            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            'createdAt' => $this->created_at,

            'updatedAt' => $this->updated_at,

        ];

    }

}