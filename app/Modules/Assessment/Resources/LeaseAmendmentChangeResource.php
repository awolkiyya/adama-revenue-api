<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaseAmendmentChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' =>
                $this->id,

            'field_name' =>
                $this->field_name,

            'value_type' =>
                $this->value_type,

            'old_value' =>
                $this->old_value,

            'new_value' =>
                $this->new_value,

            'measurement_unit_id' =>
                $this->measurement_unit_id,

            'measurement_unit' =>
                $this->whenLoaded(
                    'measurementUnit',
                    fn () => $this->measurementUnit
                        ? [
                            'id' =>
                                $this->measurementUnit->id,

                            'name' =>
                                $this->measurementUnit->name,

                            'symbol' =>
                                $this->measurementUnit->symbol,
                        ]
                        : null
                ),

            'reason' =>
                $this->reason,

            'change_order' =>
                $this->change_order,

            'created_at' =>
                $this->created_at,

            'updated_at' =>
                $this->updated_at,
        ];
    }
}