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
     *     sectors.view
     *
     * IMPORTANT:
     *
     * This only checks whether the user has permission to
     * access the sector resource.
     *
     * The actual query must be scoped to the user's
     * organizational scope.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'sectors.view'
        );
    }

    /**
     * =========================================================
     * VIEW SECTOR
     * =========================================================
     *
     * Permission:
     *
     *     sectors.view
     *
     * +
     *
     * Organizational scope.
     */
    public function view(
        User $user,
        Sector $sector
    ): bool {
        return $this->hasPermission(
            $user,
            'sectors.view'
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
