<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class UserPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY USERS
     * ============================================================
     *
     * Determines whether the user can access the user list.
     *
     * IMPORTANT:
     *
     * This only checks the permission.
     *
     * The controller/service MUST also apply the user's
     * organizational scope when querying the list.
     *
     * Example:
     *
     *      User::query()
     *          ->where(...)
     *          ->get();
     *
     * should be scope-filtered according to the authenticated user.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'users.view'
        );
    }

    /**
     * ============================================================
     * VIEW USER
     * ============================================================
     *
     * Permission:
     *
     *      users.view
     *
     * Scope:
     *
     *      target user must be inside the authenticated user's
     *      organizational scope.
     */
    public function view(User $user, User $model): bool
    {
        return $this->canAccessUser(
            $user,
            'users.view',
            $model
        );
    }

    /**
     * ============================================================
     * CREATE USER
     * ============================================================
     *
     * Creation is slightly different from viewing/updating.
     *
     * There is no existing target model to compare against.
     *
     * Therefore:
     *
     * 1. Check users.create permission.
     * 2. The request/service must validate that the NEW user's
     *    organizational scope is inside the creator's scope.
     *
     * The policy receives the proposed scope through the request
     * or a dedicated authorization method.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'users.create'
        );
    }

    /**
     * ============================================================
     * UPDATE USER
     * ============================================================
     *
     * Permission:
     *
     *      users.update
     *
     * Scope:
     *
     *      target user must belong to the authenticated user's
     *      organizational scope.
     */
    public function update(User $user, User $model): bool
    {
        return $this->canAccessUser(
            $user,
            'users.update',
            $model
        );
    }

    /**
     * ============================================================
     * DELETE USER
     * ============================================================
     *
     * Permission:
     *
     *      users.delete
     *
     * Scope:
     *
     *      target user must be inside the authenticated user's
     *      organizational scope.
     */
    public function delete(User $user, User $model): bool
    {
        /**
         * Prevent deleting yourself.
         *
         * This is a business/security rule rather than a role rule.
         */
        if ((int) $user->id === (int) $model->id) {
            return false;
        }

        return $this->canAccessUser(
            $user,
            'users.delete',
            $model
        );
    }

    /**
     * ============================================================
     * RESTORE USER
     * ============================================================
     *
     * Only relevant if User uses SoftDeletes.
     */
    public function restore(User $user, User $model): bool
    {
        return $this->canAccessUser(
            $user,
            'users.restore',
            $model
        );
    }

    /**
     * ============================================================
     * FORCE DELETE USER
     * ============================================================
     *
     * This should normally have a separate high-privilege
     * permission rather than reusing users.delete.
     */
    public function forceDelete(User $user, User $model): bool
    {
        /**
         * Prevent permanently deleting yourself.
         */
        if ((int) $user->id === (int) $model->id) {
            return false;
        }

        return $this->canAccessUser(
            $user,
            'users.force_delete',
            $model
        );
    }

    /**
     * ============================================================
     * UPDATE PASSWORD
     * ============================================================
     *
     * Updating another user's password requires its own
     * permission.
     *
     * The target user must also be inside the user's scope.
     */
    public function updatePassword(
        User $user,
        User $model
    ): bool {
        return $this->canAccessUser(
            $user,
            'users.update_password',
            $model
        );
    }
    
   /**
     * ============================================================
     * UPDATE USER STATUS
     * ============================================================
     *
     * Used for activating/deactivating users.
     *
     * Permissions:
     *
     *      users.activate
     *      users.deactivate
     *
     * The required permission depends on the target's current
     * status:
     *
     *      inactive -> active   = users.activate
     *      active   -> inactive = users.deactivate
     */
    public function updateStatus(
        User $user,
        User $model
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Prevent changing your own status
        |--------------------------------------------------------------------------
        */

        if ((string) $user->id === (string) $model->id) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Determine required permission
        |--------------------------------------------------------------------------
        |
        | Current status:
        |
        | true  = currently active
        | false = currently inactive
        |
        | updateStatus toggles the current state.
        |
        */

        $permission = $model->is_active
            ? 'users.deactivate'
            : 'users.activate';

        /*
        |--------------------------------------------------------------------------
        | Check permission + organizational scope
        |--------------------------------------------------------------------------
        */

        return $this->canAccessUser(
            $user,
            $permission,
            $model
        );
    }

    /**
     * ============================================================
     * ASSIGN ROLE
     * ============================================================
     *
     * Role assignment is treated as a permission.
     *
     * The actual validation of whether the selected role can be
     * assigned should happen in the service/request layer.
     *
     * The policy still verifies that the target user is within
     * the administrator's scope.
     */
    public function assignRole(
        User $user,
        User $model
    ): bool {
        return $this->canAccessUser(
            $user,
            'users.assign_role',
            $model
        );
    }

    /**
     * ============================================================
     * MANAGE
     * ============================================================
     *
     * Optional convenience ability for screens/actions that
     * require general user-management access.
     *
     * Do not use this as a replacement for specific permissions
     * when fine-grained authorization is required.
     */
    public function manage(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'users.manage'
        );
    }
}

