<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TariffVersionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,


            /*
            |--------------------------------------------------------------------------
            | Tariff Information
            |--------------------------------------------------------------------------
            */

            'year' => $this->year,

            'version' => $this->version,

            'name' => $this->name,

            'description' => $this->description,


            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effectiveFrom' =>
                $this->effective_from?->format('Y-m-d'),


            'effectiveTo' =>
                $this->effective_to?->format('Y-m-d'),



            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'isActive' => $this->is_active,


            'isCurrentlyEffective' =>
                $this->is_currently_effective,



            /*
            |--------------------------------------------------------------------------
            | Display
            |--------------------------------------------------------------------------
            */

            'displayName' =>
                $this->display_name,



            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            'tariffRulesCount' =>
                $this->whenCounted('tariffRules'),


            // 'tariff_rules' =>
            //     TariffRuleResource::collection(
            //         $this->whenLoaded('tariffRules')
            //     ),



            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'createdAt' =>
                $this->created_at?->format('Y-m-d H:i:s'),


            'updatedAt' =>
                $this->updated_at?->format('Y-m-d H:i:s'),

        ];
    }
}