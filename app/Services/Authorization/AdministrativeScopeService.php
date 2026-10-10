<?php

namespace App\Services\Authorization;

use App\Models\AdministrativeUnit;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdministrativeScopeService
{
    /**
     * Determine whether the user has global administrative access.
     */
    public function isGlobal(User $user): bool
    {
        return $user->hasRole('SYSTEM_ADMIN', 'api');
    }

    /**
     * Return the administrative unit IDs accessible to a user.
     *
     * A user assigned to a parent administrative unit can access
     * that unit and all its descendants.
     */
    public function accessibleUnitIds(User $user): array
    {
        if ($this->isGlobal($user)) {
            return AdministrativeUnit::query()
                ->pluck('id')
                ->all();
        }

        if (!$user->administrative_unit_id) {
            return [];
        }

        $rootId = (string) $user->administrative_unit_id;

        $accessibleIds = [$rootId];
        $frontier = [$rootId];

        while (!empty($frontier)) {
            $childIds = AdministrativeUnit::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $newIds = array_values(
                array_diff($childIds, $accessibleIds)
            );

            if (empty($newIds)) {
                break;
            }

            $accessibleIds = array_merge(
                $accessibleIds,
                $newIds
            );

            $frontier = $newIds;
        }

        return array_values(array_unique($accessibleIds));
    }

    /**
     * Return the sector IDs accessible to a user.
     *
     * This represents direct sector assignment, not service-level
     * authorization. Service access is handled separately by
     * SectorAccessService.
     */
    public function accessibleSectorIds(User $user): array
    {
        if ($this->isGlobal($user)) {
            return Sector::query()
                ->pluck('id')
                ->all();
        }

        if (!$user->sector_id) {
            return [];
        }

        return [(string) $user->sector_id];
    }

    /**
     * Apply administrative-unit scope to a query.
     *
     * Use for models that have an administrative_unit_id column.
     */
    public function applyAdministrativeScope(
        Builder $query,
        User $user,
        string $column = 'administrative_unit_id'
    ): Builder {
        $this->validateColumnName($column);

        if ($this->isGlobal($user)) {
            return $query;
        }

        $unitIds = $this->accessibleUnitIds($user);

        if (empty($unitIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $unitIds);
    }

    /**
     * Apply direct sector-assignment scope to a query.
     *
     * Use only when the queried model actually has a sector_id
     * column. Do not use this method for assessments or
     * revenue_services in the current schema.
     */
    public function applySectorScope(
        Builder $query,
        User $user,
        string $column = 'sector_id'
    ): Builder {
        $this->validateColumnName($column);

        if ($this->isGlobal($user)) {
            return $query;
        }

        $sectorIds = $this->accessibleSectorIds($user);

        if (empty($sectorIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $sectorIds);
    }

    /**
     * Apply both administrative-unit and sector scopes.
     *
     * Use only when the queried table contains both columns.
     */
    public function apply(
        Builder $query,
        User $user,
        string $unitColumn = 'administrative_unit_id',
        string $sectorColumn = 'sector_id'
    ): Builder {
        $this->validateColumnName($unitColumn);
        $this->validateColumnName($sectorColumn);

        if ($this->isGlobal($user)) {
            return $query;
        }

        $unitIds = $this->accessibleUnitIds($user);
        $sectorIds = $this->accessibleSectorIds($user);

        if (empty($unitIds) || empty($sectorIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn($unitColumn, $unitIds)
            ->whereIn($sectorColumn, $sectorIds);
    }

    /**
     * Check whether a user can access an administrative unit.
     */
    public function canAccessUnit(
        User $user,
        ?string $administrativeUnitId
    ): bool {
        if (!$administrativeUnitId) {
            return false;
        }

        if ($this->isGlobal($user)) {
            return true;
        }

        return in_array(
            $administrativeUnitId,
            $this->accessibleUnitIds($user),
            true
        );
    }

    /**
     * Check whether a user has a direct assignment to a sector.
     */
    public function canAccessSector(
        User $user,
        ?string $sectorId
    ): bool {
        if (!$sectorId) {
            return false;
        }

        if ($this->isGlobal($user)) {
            return true;
        }

        return in_array(
            $sectorId,
            $this->accessibleSectorIds($user),
            true
        );
    }

    /**
     * Check both administrative-unit and direct sector assignments.
     *
     * Use only when the target record has both assignments.
     */
    public function canAccess(
        User $user,
        ?string $administrativeUnitId,
        ?string $sectorId
    ): bool {
        if ($this->isGlobal($user)) {
            return true;
        }

        return $this->canAccessUnit(
            $user,
            $administrativeUnitId
        ) && $this->canAccessSector(
            $user,
            $sectorId
        );
    }

    /**
     * Validate a simple or table-qualified SQL column identifier.
     */
    private function validateColumnName(string $column): void
    {
        if (!preg_match(
            '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/',
            $column
        )) {
            throw new \InvalidArgumentException(
                'Invalid SQL column identifier.'
            );
        }
    }
}