<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class RevenueCategoryResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [

            /**
             * Identity
             */
            'id' => (string) $this->id,


            /**
             * Revenue classification
             */
            'revenueDomain' => $this->revenue_domain,


            /**
             * Category information
             */
            'name' => $this->name,

            'startCode' => $this->start_code,

            'endCode' => $this->end_code,


            'description' => $this->description,


            /**
             * Ordering
             */
            'sortOrder' => $this->sort_order,


            /**
             * Status
             */
            'isActive' => (bool) $this->is_active,

            'status' => $this->is_active
                ? 'ACTIVE'
                : 'INACTIVE',



            /**
             * Children
             */
            'codesCount' => $this->whenCounted(
                'codes'
            ),


            'codes' => RevenueCodeResource::collection(
                $this->whenLoaded('codes')
            ),



            /**
             * Audit
             */
            'created_at' =>
                $this->created_at?->toIso8601String(),


            'updated_at' =>
                $this->updated_at?->toIso8601String(),

        ];
    }
}