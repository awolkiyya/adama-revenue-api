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
     *     administrative_units.view
     *
     * This determines whether the user can access the
     * administrative-unit resource.
     *
     * The actual query must still be scope-aware.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'administrative_units.view'
        );
    }

    /**
     * =========================================================
     * VIEW ADMINISTRATIVE UNIT
     * =========================================================
     *
     * Permission:
     *
     *     administrative_units.view
     *
     * +
     *
     * Organizational scope.
     */
    public function view(
        User $user,
        AdministrativeUnit $unit
    ): bool {
        return $this->hasPermission(
            $user,
            'administrative_units.view'
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
