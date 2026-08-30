<?php

namespace App\Modules\System\AccessManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'module' => $this['module'],
            
            'key' => $this['key'],

            'permissions' => PermissionResource::collection(
                collect($this['permissions'])
            ),
        ];
    }
}