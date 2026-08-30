<?php

namespace App\Modules\System\AccessManagement\Services;

use Spatie\Permission\Models\Permission;

class PermissionService
{
    public function grouped()
    {
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(function ($permission) {

                return explode('.', $permission->name)[0];

            })
            ->map(function ($items, $module) {

                return [
                    'module' => ucfirst(
                        str_replace('_', ' ', $module)
                    ),

                    'key' => $module,

                    'permissions' => $items,
                ];

            })
            ->values();
    }
}