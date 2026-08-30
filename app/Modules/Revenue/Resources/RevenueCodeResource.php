<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            /**
             * Identity
             */
            'id' => (string) $this->id,


            /**
             * Parent category
             */
            'categoryId' => (string) $this->category_id,



            /**
             * Category details
             *
             * Returned only when loaded
             */
            'category' => new RevenueCategoryResource(
                $this->whenLoaded('category')
            ),



            /**
             * Revenue code information
             */
            'code' => $this->code,

            'name' => $this->name,

            'description' => $this->description,



            /**
             * Status
             */
            'isActive' => (bool) $this->is_active,



            /**
             * Audit
             */
            'createdAt' => $this->created_at?->toIso8601String(),

            'updatedAt' => $this->updated_at?->toIso8601String(),

        ];
    }
}