<?php

namespace App\Modules\System\AccessManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => (string) $this->id,
            'name'             => $this->name,
            'description'      => $this->description,

            'usersCount'       => $this->users_count ?? $this->users()->count(),
            'permissionsCount' => $this->permissions_count ?? $this->permissions()->count(),

            'permissions'      => PermissionResource::collection(
                $this->whenLoaded('permissions')
            ),

            'createdAt'        => $this->created_at?->toDateString(),
        ];
    }
}