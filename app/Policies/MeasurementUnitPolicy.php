<?php

namespace App\Policies;

use App\Models\User;
use App\Models\MeasurementUnit;
use App\Policies\Concerns\ChecksHierarchy;

class MeasurementUnitPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY MEASUREMENT UNITS
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.view
     *
     * Allows viewing the measurement unit list.
     *
     * Hierarchy is evaluated through hasPermission().
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'measurement_units.view'
        );
    }

    /**
     * ============================================================
     * VIEW MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.view
     *
     * Allows viewing an individual measurement unit.
     */
    public function view(
        User $user,
        MeasurementUnit $measurementUnit
    ): bool {
        return $this->hasPermission(
            $user,
            'measurement_units.view'
        );
    }

    /**
     * ============================================================
     * CREATE MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.create
     *
     * Allows creating a measurement unit.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'measurement_units.create'
        );
    }

    /**
     * ============================================================
     * UPDATE MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.update
     *
     * Allows updating a measurement unit.
     */
    public function update(
        User $user,
        MeasurementUnit $measurementUnit
    ): bool {
        return $this->hasPermission(
            $user,
            'measurement_units.update'
        );
    }

    /**
     * ============================================================
     * DELETE MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.delete
     *
     * Allows deleting a measurement unit.
     */
    public function delete(
        User $user,
        MeasurementUnit $measurementUnit
    ): bool {
        return $this->hasPermission(
            $user,
            'measurement_units.delete'
        );
    }

    /**
     * ============================================================
     * RESTORE MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.restore
     *
     * Allows restoring a deleted measurement unit.
     */
    public function restore(
        User $user,
        MeasurementUnit $measurementUnit
    ): bool {
        return $this->hasPermission(
            $user,
            'measurement_units.restore'
        );
    }

    /**
     * ============================================================
     * ACTIVATE MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.activate
     *
     * Allows activating a measurement unit.
     */
    public function activate(
        User $user,
        MeasurementUnit $measurementUnit
    ): bool {
        return $this->hasPermission(
            $user,
            'measurement_units.activate'
        );
    }

    /**
     * ============================================================
     * DEACTIVATE MEASUREMENT UNIT
     * ============================================================
     *
     * Permission:
     *
     *     measurement_units.deactivate
     *
     * Allows deactivating a measurement unit.
     */
    public function deactivate(
        User $user,
        MeasurementUnit $measurementUnit
    ): bool {
        return $this->hasPermission(
            $user,
            'measurement_units.deactivate'
        );
    }
}
