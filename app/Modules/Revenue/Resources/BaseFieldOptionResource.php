<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BaseFieldOptionResource extends JsonResource
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
            | Base Field
            |--------------------------------------------------------------------------
            */

            'base_field_id' => $this->base_field_id,

            /*
            |--------------------------------------------------------------------------
            | Option
            |--------------------------------------------------------------------------
            */

            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Ordering
            |--------------------------------------------------------------------------
            */

            'sort_order' => (int) $this->sort_order,

            /*
            |--------------------------------------------------------------------------
            | Default
            |--------------------------------------------------------------------------
            */

            'is_default' => (bool) $this->is_default,

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
            | Dates
            |--------------------------------------------------------------------------
            */

            'created_at' => optional($this->created_at)->toDateTimeString(),

            'updated_at' => optional($this->updated_at)->toDateTimeString(),

            'deleted_at' => optional($this->deleted_at)->toDateTimeString(),
        ];
    }
}