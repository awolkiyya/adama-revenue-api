<?php

declare(strict_types=1);

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

            'year' => (int) $this->year,

            'version' => (int) $this->version,

            'name' => $this->name,

            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effectiveFrom' => $this->effective_from?->toDateString(),

            'effectiveTo' => $this->effective_to?->toDateString(),

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            /**
             * Whether this tariff version is selected for new assessments.
             */
            'isActive' => (bool) $this->is_active,

            /**
             * Whether today's date falls within the effective date range.
             * Independent of isActive.
             */
            'isCurrentlyEffective' => (bool) $this->is_currently_effective,

            /*
            |--------------------------------------------------------------------------
            | Display
            |--------------------------------------------------------------------------
            */

            'displayName' => $this->display_name,

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            'tariffRulesCount' => $this->whenCounted('tariffRules'),

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),

            'updatedAt' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
