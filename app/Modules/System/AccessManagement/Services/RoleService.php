<?php

namespace App\Modules\System\AccessManagement\Services;

use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RoleService
{
    /**
     * ============================================================
     * PAGINATE ROLES
     * ============================================================
     *
     * Returns roles with:
     *
     * - assigned permissions
     * - number of users
     * - number of permissions
     *
     * Authorization is intentionally NOT handled here.
     * RolePolicy is responsible for access control.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $pageSize = min(
            max((int) ($filters['pageSize'] ?? 10), 1),
            100
        );

        return Role::query()
            ->with([
                'permissions:id,name,guard_name',
            ])
            ->withCount([
                'users',
                'permissions',
            ])
            ->when(
                !empty($filters['search']),
                function ($query) use ($filters) {
                    $search = trim(
                        (string) $filters['search']
                    );

                    $query->where(function ($q) use ($search) {
                        $q->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'description',
                            'like',
                            "%{$search}%"
                        );
                    });
                }
            )
            ->orderByDesc('created_at')
            ->paginate($pageSize);
    }

    /**
     * ============================================================
     * FIND ROLE
     * ============================================================
     *
     * Returns a role with its permissions and related counts.
     *
     * Authorization is handled by RolePolicy in the controller.
     */
    public function findById(string $id): Role
    {
        return Role::query()
            ->with([
                'permissions:id,name,label,module,description,guard_name',
            ])
            ->withCount([
                'users',
                'permissions',
            ])
            ->findOrFail($id);
    }

    /**
     * ============================================================
     * ROLE HISTORY
     * ============================================================
     *
     * Authorization is handled by RolePolicy.
     *
     * The actual implementation depends on your audit/history
     * system.
     */
    public function history(Role $role)
    {
        // Implement using your audit/history repository.
        //
        // Example:
        //
        // return $role->audits()
        //     ->latest()
        //     ->paginate(20);

        return collect();
    }
}