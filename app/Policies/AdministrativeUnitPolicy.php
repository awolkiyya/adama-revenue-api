<?php

namespace App\Policies;

use App\Models\AdministrativeUnit;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class AdministrativeUnitPolicy
{
    use ChecksHierarchy;

    /**
     * =========================================================
     * VIEW ANY ADMINISTRATIVE UNITS
     * =========================================================
     *
     * Permission:
     *
     *     administrative_units.read
     *
     * This determines whether the user can retrieve/list
     * administrative units.
     *
     * `administrative_units.view` is reserved for accessing
     * the Administrative Units management interface.
     *
     * The actual query must still be scope-aware.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'administrative_units.read'
        );
    }

    /**
     * =========================================================
     * VIEW ADMINISTRATIVE UNIT
     * =========================================================
     *
     * Permission:
     *
     *     administrative_units.read
     *
     * +
     *
     * Organizational scope.
     *
     * A user must have read permission and the requested
     * administrative unit must belong to their organizational
     * scope.
     */
    public function view(
        User $user,
        AdministrativeUnit $unit
    ): bool {
        return $this->hasPermission(
            $user,
            'administrative_units.read'
        ) && $this->hasAdministrativeUnitScope(
            $user,
            $unit
        );
    }

    /**
     * =========================================================
     * CREATE ADMINISTRATIVE UNIT
     * =========================================================
     *
     * Permission:
     *
     *     administrative_units.create
     *
     * Creation scope should be validated against the
     * requested parent_id/level before the record is created.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'administrative_units.create'
        );
    }

    /**
     * =========================================================
     * UPDATE ADMINISTRATIVE UNIT
     * =========================================================
     *
     * Permission:
     *
     *     administrative_units.update
     *
     * +
     *
     * Organizational scope.
     */
    public function update(
        User $user,
        AdministrativeUnit $unit
    ): bool {
        return $this->hasPermission(
            $user,
            'administrative_units.update'
        ) && $this->hasAdministrativeUnitScope(
            $user,
            $unit
        );
    }

    /**
     * =========================================================
     * DELETE ADMINISTRATIVE UNIT
     * =========================================================
     *
     * Permission:
     *
     *     administrative_units.delete
     *
     * +
     *
     * Organizational scope.
     */
    public function delete(
        User $user,
        AdministrativeUnit $unit
    ): bool {
        return $this->hasPermission(
            $user,
            'administrative_units.delete'
        ) && $this->hasAdministrativeUnitScope(
            $user,
            $unit
        );
    }

    /**
     * =========================================================
     * ADMINISTRATIVE UNIT SCOPE
     * =========================================================
     *
     * Determines whether the administrative unit belongs
     * to the authenticated user's organizational scope.
     *
     * This method does NOT check permissions.
     *
     * It only checks scope.
     */
    protected function hasAdministrativeUnitScope(
        User $user,
        AdministrativeUnit $unit
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | CITY SCOPE
        |--------------------------------------------------------------------------
        |
        | A city-scoped user can access:
        |
        |     CITY
        |     ├── SUBCITY
        |     │   └── WEREDA
        |     └── ...
        |
        | The unit's city is determined from the hierarchy.
        |
        */

        if ($user->city_id !== null) {
            $cityId = $this->resolveCityId($unit);

            if (
                $cityId !== null
                && (int) $user->city_id === (int) $cityId
            ) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SUBCITY SCOPE
        |--------------------------------------------------------------------------
        |
        | A subcity-scoped user can access:
        |
        |     SUBCITY
        |     └── WEREDA
        |
        */

        if ($user->subcity_id !== null) {
            $subcityId = $this->resolveSubcityId($unit);

            if (
                $subcityId !== null
                && (int) $user->subcity_id === (int) $subcityId
            ) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | WEREDA SCOPE
        |--------------------------------------------------------------------------
        */

        if ($user->wereda_id !== null) {
            $weredaId = $this->resolveWeredaId($unit);

            if (
                $weredaId !== null
                && (int) $user->wereda_id === (int) $weredaId
            ) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | NO MATCH
        |--------------------------------------------------------------------------
        */

        return false;
    }

    /**
     * =========================================================
     * RESOLVE CITY
     * =========================================================
     *
     * Walks up the administrative hierarchy until the
     * CITY-level ancestor is found.
     */
    protected function resolveCityId(
        AdministrativeUnit $unit
    ): ?int {
        $current = $unit;

        while ($current) {
            if (
                $current->level === 'CITY'
                && $current->id !== null
            ) {
                return (int) $current->id;
            }

            if (!$current->parent_id) {
                break;
            }

            $current = AdministrativeUnit::query()
                ->select([
                    'id',
                    'parent_id',
                    'level',
                ])
                ->find($current->parent_id);
        }

        return null;
    }

    /**
     * =========================================================
     * RESOLVE SUBCITY
     * =========================================================
     *
     * Walks up the administrative hierarchy until the
     * SUBCITY-level ancestor is found.
     */
    protected function resolveSubcityId(
        AdministrativeUnit $unit
    ): ?int {
        $current = $unit;

        while ($current) {
            if (
                $current->level === 'SUBCITY'
                && $current->id !== null
            ) {
                return (int) $current->id;
            }

            if (!$current->parent_id) {
                break;
            }

            $current = AdministrativeUnit::query()
                ->select([
                    'id',
                    'parent_id',
                    'level',
                ])
                ->find($current->parent_id);
        }

        return null;
    }

    /**
     * =========================================================
     * RESOLVE WEREDA
     * =========================================================
     *
     * Walks up the administrative hierarchy until the
     * WEREDA-level ancestor is found.
     */
    protected function resolveWeredaId(
        AdministrativeUnit $unit
    ): ?int {
        $current = $unit;

        while ($current) {
            if (
                $current->level === 'WEREDA'
                && $current->id !== null
            ) {
                return (int) $current->id;
            }

            if (!$current->parent_id) {
                break;
            }

            $current = AdministrativeUnit::query()
                ->select([
                    'id',
                    'parent_id',
                    'level',
                ])
                ->find($current->parent_id);
        }

        return null;
    }
}