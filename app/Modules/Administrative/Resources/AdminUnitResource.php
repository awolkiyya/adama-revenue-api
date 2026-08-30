<?php

namespace App\Modules\Administrative\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUnitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'code'       => $this->code,
            'level'      => $this->level,
            'is_active'  => (bool) $this->is_active,
            'parent_id'  => $this->parent_id,
            
            // This will only appear in the JSON if 'children' is loaded in the controller
            // E.g., AdministrativeUnit::with('children')->get()
            'children'   => AdminUnitResource::collection($this->whenLoaded('children')),
            
            'audit' => [
                'created_by' => $this->created_by,
                'updated_by' => $this->updated_by,
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
        ];
    }
}