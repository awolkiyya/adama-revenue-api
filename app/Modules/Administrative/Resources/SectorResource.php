<?php

namespace App\Modules\Administrative\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'description' => $this->description,
            'is_active'   => (bool) $this->is_active,
            
            // Grouped contact information for better frontend structure
            'contact'     => [
                'phone'   => $this->phone,
                'email'   => $this->email,
            ],

            // Relationship: Only include if the 'cluster' is loaded in the controller
            // usage: Sector::with('cluster')->get();
            'cluster'     => new ClusterResource($this->whenLoaded('cluster')),
            'cluster_id'  => $this->cluster_id,

            // Audit fields grouped
            'audit'       => [
                'created_by' => $this->created_by,
                'updated_by' => $this->updated_by,
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
        ];
    }
}