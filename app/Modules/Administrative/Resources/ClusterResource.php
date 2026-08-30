<?php

namespace App\Modules\Administrative\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClusterResource extends JsonResource
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

            // Relationship: Include sectors only if they are eager-loaded in the controller
            // E.g., Cluster::with('sectors')->get();
            'sectors'     => SectorResource::collection($this->whenLoaded('sectors')),

            'created_at'  => $this->created_at?->toDateTimeString(),
        ];
    }
}