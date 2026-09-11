<?php

namespace App\Policies;

use App\Models\Sector;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class SectorPolicy
{
    use ChecksHierarchy;

    /**
     * =========================================================
     * VIEW ANY SECTORS
     * =========================================================
     *
     * Permission:
     *
     *     sectors.read
     *
     * This checks whether the user can retrieve/list
     * sector records.
     *
     * `sectors.view` is reserved for accessing the
     * Sector Management interface.
     *
     * IMPORTANT:
     *
     * The actual query must still be scoped to the user's
     * organizational scope.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'sectors.read'
        );
    }

    /**
     * =========================================================
     * VIEW SECTOR
     * =========================================================
     *
     * Permission:
     *
     *     sectors.read
     *
     * +
     *
     * Organizational scope.
     *
     * The user must have read permission and the requested
     * sector must belong to their organizational scope.
     */
    public function view(
        User $user,
        Sector $sector
    ): bool {
        return $this->hasPermission(
            $user,
            'sectors.read'
        ) && $this->hasAccessToModel(
            $user,
            $sector
        );
    }

    /**
     * =========================================================
     * CREATE SECTOR
     * =========================================================
     *
     * Permission:
     *
     *     sectors.create
     *
     * Sector creation does not have an existing model yet,
     * therefore only permission is checked here.
     *
     * If sector creation must be restricted by city/cluster,
     * validate that scope before creating the record.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'sectors.create'
        );
    }

    /**
     * =========================================================
     * UPDATE SECTOR
     * =========================================================
     *
     * Permission:
     *
     *     sectors.update
     *
     * +
     *
     * Organizational scope.
     */
    public function update(
        User $user,
        Sector $sector
    ): bool {
        return $this->hasPermission(
            $user,
            'sectors.update'
        ) && $this->hasAccessToModel(
            $user,
            $sector
        );
    }

    /**
     * =========================================================
     * DELETE SECTOR
     * =========================================================
     *
     * Permission:
     *
     *     sectors.delete
     *
     * +
     *
     * Organizational scope.
     */
    public function delete(
        User $user,
        Sector $sector
    ): bool {
        return $this->hasPermission(
            $user,
            'sectors.delete'
        ) && $this->hasAccessToModel(
            $user,
            $sector
        );
    }
}