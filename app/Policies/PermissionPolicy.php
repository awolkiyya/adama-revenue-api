<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Permission;

class PermissionPolicy
{
    /**
     * ============================================================
     * VIEW ANY PERMISSIONS
     * ============================================================
     *
     * Permission:
     *
     *     permissions.view
     *
     * Allows viewing the permission catalog available in the
     * application.
     *
     * Permissions are application-defined capabilities and are
     * normally not created or deleted through the administration
     * UI.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('permissions.view');
    }

    /**
     * ============================================================
     * VIEW PERMISSION
     * ============================================================
     *
     * Permission:
     *
     *     permissions.view
     *
     * Allows viewing an individual permission definition.
     */
    public function view(
        User $user,
        Permission $permission
    ): bool {
        return $user->can('permissions.view');
    }
}