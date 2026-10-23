<?php

declare(strict_types=1);

namespace App\Modules\System\AccessManagement\Services;

use App\Models\Permission;
use Illuminate\Support\Collection;

class PermissionService
{
    /**
     * Get API permissions grouped by their resource prefix.
     */
    public function list(): Collection
    {
        return Permission::query()
            ->where('guard_name', 'api')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'guard_name',
            ])
            ->groupBy(
                static function (Permission $permission): string {
                    return explode('.', $permission->name, 2)[0];
                }
            )
            ->map(
                static function (
                    Collection $permissions,
                    string $module
                ): array {
                    return [
                        'module' => ucfirst(
                            str_replace('_', ' ', $module)
                        ),
                        'key' => $module,
                        'permissions' => $permissions->values(),
                    ];
                }
            )
            ->values();
    }
}
