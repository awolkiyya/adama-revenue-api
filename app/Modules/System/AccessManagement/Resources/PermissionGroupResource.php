<?php

declare(strict_types=1);

namespace App\Modules\System\AccessManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionGroupResource extends JsonResource
{
    /**
     * Transform a permission group into an API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'module' => $this->resource['module'],
            'key' => $this->resource['key'],

            'permissions' => PermissionResource::collection(
                $this->resource['permissions']
            ),
        ];
    }
}
