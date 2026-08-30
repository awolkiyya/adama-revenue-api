<?php

namespace App\Policies;

use App\Models\Citizen;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class CitizenPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY CITIZENS
     * ============================================================
     *
     * Permission:
     *
     *     citizens.view
     *
     * Any authenticated user with citizens.view can access
     * the citizen list.
     *
     * No organizational scope restriction applies.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'citizens.view'
        );
    }

    /**
     * ============================================================
     * VIEW CITIZEN
     * ============================================================
     *
     * Permission:
     *
     *     citizens.view
     *
     * Citizens are globally managed resources.
     *
     * Therefore, no CITY/SUBCITY/WEREDA/SECTOR or
     * administrative-unit scope check is performed here.
     */
    public function view(
        User $user,
        Citizen $citizen
    ): bool {
        return $this->hasPermission(
            $user,
            'citizens.view'
        );
    }

    /**
     * ============================================================
     * CREATE CITIZEN
     * ============================================================
     *
     * Permission:
     *
     *     citizens.create
     *
     * Any authenticated user with this permission can register
     * a citizen.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'citizens.create'
        );
    }

    /**
     * ============================================================
     * UPDATE CITIZEN
     * ============================================================
     *
     * Permission:
     *
     *     citizens.update
     *
     * Citizens are globally managed resources.
     *
     * Therefore, no organizational scope restriction applies.
     */
    public function update(
        User $user,
        Citizen $citizen
    ): bool {
        return $this->hasPermission(
            $user,
            'citizens.update'
        );
    }

    /**
     * ============================================================
     * VERIFY CITIZEN
     * ============================================================
     *
     * Permission:
     *
     *     citizens.verify
     *
     * Any authenticated user with this permission can verify
     * a citizen.
     */
    public function verify(
        User $user,
        Citizen $citizen
    ): bool {
        return $this->hasPermission(
            $user,
            'citizens.verify'
        );
    }

    /**
     * ============================================================
     * IMPORT CITIZENS
     * ============================================================
     *
     * Permission:
     *
     *     citizens.import
     *
     * Any authenticated user with this permission can import
     * citizen records.
     *
     * The import request/service should still validate the
     * imported data itself.
     */
    public function import(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'citizens.import'
        );
    }

    /**
     * ============================================================
     * VIEW CITIZEN HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     citizens.view_history
     *
     * Citizen history is also globally accessible to users
     * who have this permission.
     */
    public function viewHistory(
        User $user,
        Citizen $citizen
    ): bool {
        return $this->hasPermission(
            $user,
            'citizens.view_history'
        );
    }
}