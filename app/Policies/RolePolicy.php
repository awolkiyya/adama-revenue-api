<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class RolePolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY ROLES
     * ============================================================
     *
     * Permission:
     *
     *     roles.view
     *
     * Allows viewing the role list.
     *
     * Roles are system access-control resources, so no
     * organizational hierarchy restriction is applied.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'roles.view'
        );
    }

    /**
     * ============================================================
     * VIEW ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.view
     *
     * Allows viewing an individual role and its permissions.
     */
    public function view(
        User $user,
        Role $role
    ): bool {
        return $this->hasPermission(
            $user,
            'roles.view'
        );
    }

    /**
     * ============================================================
     * CREATE ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.create
     *
     * Allows creating a non-system role.
     *
     * Permission definitions themselves are not created here.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'roles.create'
        );
    }

    /**
     * ============================================================
     * UPDATE ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.update
     *
     * Allows updating eligible non-system roles.
     *
     * This covers role metadata such as:
     *
     *     - name
     *     - description
     *
     * If the request also changes permissions, the controller
     * must additionally authorize assignPermissions() and/or
     * revokePermissions().
     */
    public function update(
        User $user,
        Role $role
    ): bool {
        return $this->hasPermission(
            $user,
            'roles.update'
        )
        && ! $role->isSystem();
    }

    /**
     * ============================================================
     * DELETE ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.delete
     *
     * System roles cannot be deleted.
     */
    public function delete(
        User $user,
        Role $role
    ): bool {
        return $this->hasPermission(
            $user,
            'roles.delete'
        )
        && ! $role->isSystem();
    }

    /**
     * ============================================================
     * ASSIGN PERMISSIONS
     * ============================================================
     *
     * Permission:
     *
     *     roles.assign_permissions
     *
     * Allows assigning existing permissions to a non-system role.
     *
     * This does NOT create permission definitions.
     */
    public function assignPermissions(
        User $user,
        Role $role
    ): bool {
        return $this->hasPermission(
            $user,
            'roles.assign_permissions'
        )
        && ! $role->isSystem();
    }

    /**
     * ============================================================
     * REVOKE PERMISSIONS
     * ============================================================
     *
     * Permission:
     *
     *     roles.revoke_permissions
     *
     * Allows revoking permissions from a non-system role.
     */
    public function revokePermissions(
        User $user,
        Role $role
    ): bool {
        return $this->hasPermission(
            $user,
            'roles.revoke_permissions'
        )
        && ! $role->isSystem();
    }

    /**
     * ============================================================
     * VIEW ROLE HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     roles.view_history
     *
     * Allows viewing role and permission assignment history.
     */
    public function viewHistory(
        User $user,
        Role $role
    ): bool {
        return $this->hasPermission(
            $user,
            'roles.view_history'
        );
    }
}