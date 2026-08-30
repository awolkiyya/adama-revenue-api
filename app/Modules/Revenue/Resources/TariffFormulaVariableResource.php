<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TariffFormulaVariableResource extends JsonResource
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

            'variableName' => $this->variable_name,

            'label' => $this->label,

            /*
            |--------------------------------------------------------------------------
            | Source
            |--------------------------------------------------------------------------
            */

            'sourceType' => $this->source_type,

            'baseFieldId' => $this->base_field_id,

            'defaultValue' => $this->default_value,

            /*
            |--------------------------------------------------------------------------
            | Type
            |--------------------------------------------------------------------------
            */

            'dataType' => $this->data_type,

            /*
            |--------------------------------------------------------------------------
            | Behaviour
            |--------------------------------------------------------------------------
            */

            'isRequired' => $this->is_required,

            'sortOrder' => $this->sort_order,

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            'baseField' => $this->whenLoaded(
                'baseField',
                fn () => new BaseFieldResource($this->baseField)
            ),

            /*
            |--------------------------------------------------------------------------
            | System
            |--------------------------------------------------------------------------
            */

            'createdAt' => $this->created_at?->toISOString(),

            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}