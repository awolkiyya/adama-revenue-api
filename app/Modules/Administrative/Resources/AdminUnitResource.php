<?php

declare(strict_types=1);

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
            'id'        => $this->id,
            'name'      => $this->name,
            'code'      => $this->code,
            'level'     => $this->level,
            'is_active' => (bool) $this->is_active,

            // Keep the foreign key for compatibility and filtering.
            'parent_id' => $this->parent_id,

            // Include parent details only when the relationship is loaded.
            // The parent is intentionally shallow to prevent recursive nesting.
            'parent' => $this->whenLoaded(
                'parent',
                fn () => $this->parent
                    ? [
                        'id'    => $this->parent->id,
                        'name'  => $this->parent->name,
                        'code'  => $this->parent->code,
                        'level' => $this->parent->level,
                    ]
                    : null,
            ),

            // Include child units only when the relationship is loaded.
            'children' => AdminUnitResource::collection(
                $this->whenLoaded('children')
            ),

            // Audit metadata.
            'audit' => [
                'created_by' => $this->created_by,
                'updated_by' => $this->updated_by,
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
        ];
    }
}
