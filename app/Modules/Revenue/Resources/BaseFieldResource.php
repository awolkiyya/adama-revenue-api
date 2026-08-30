<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BaseFieldResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            */

            'measurement_unit_id' => $this->measurement_unit_id,

            'measurement_unit' => MeasurementUnitResource::make(
                $this->whenLoaded('measurementUnit')
            ),

            'unit' => $this->whenLoaded(
                'measurementUnit',
                fn () => $this->measurementUnit?->symbol
            ),

            'unit_name' => $this->whenLoaded(
                'measurementUnit',
                fn () => $this->measurementUnit?->name
            ),

            'unit_code' => $this->whenLoaded(
                'measurementUnit',
                fn () => $this->measurementUnit?->code
            ),

            /*
            |--------------------------------------------------------------------------
            | Data Type
            |--------------------------------------------------------------------------
            */

            'data_type' => $this->data_type,

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            |
            | Options are relevant for:
            |
            | SELECT
            | RADIO
            | CHECKBOX
            |
            | The relationship is only serialized when it has been
            | explicitly eager-loaded, preventing accidental N+1 queries.
            |
            */

            'options' => BaseFieldOptionResource::collection(
                $this->whenLoaded('options')
            ),

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => (bool) $this->is_active,

            'status' => $this->is_active
                ? 'ACTIVE'
                : 'INACTIVE',

            /*
            |--------------------------------------------------------------------------
            | Ordering
            |--------------------------------------------------------------------------
            */

            'sort_order' => (int) $this->sort_order,

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toDateTimeString(),

            'updated_at' => $this->updated_at?->toDateTimeString(),

            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }
}