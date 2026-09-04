<?php

namespace App\Policies;

use App\Models\RevenueSetting;
use App\Models\User;

/**
 * Revenue Setting Policy
 *
 * Controls authorization for the global Revenue Management configuration.
 *
 * Revenue settings are system-wide configuration and are intentionally
 * separated from:
 *
 * - Tariff Rules
 * - Penalty Rules
 * - Interest Rules
 * - Invoice records
 * - Payment records
 * - Receipt records
 *
 * This policy therefore only handles viewing and updating the global
 * revenue configuration.
 *
 * IMPORTANT:
 *
 * RevenueSetting is a singleton-style configuration. It is not a normal
 * CRUD resource and does not expose create, delete, activate, or deactivate
 * authorization operations.
 */
class RevenueSettingPolicy
{
    /*
    |--------------------------------------------------------------------------
    | View Any Revenue Settings
    |--------------------------------------------------------------------------
    |
    | Allows the user to access the Revenue Settings section.
    |
    */

    public function viewAny(User $user): bool
    {
        return $user->can('revenue_settings.view');
    }


    /*
    |--------------------------------------------------------------------------
    | View Revenue Setting
    |--------------------------------------------------------------------------
    |
    | Allows the user to view the global revenue configuration.
    |
    */

    public function view(
        User $user,
        RevenueSetting $revenueSetting
    ): bool {
        return $user->can('revenue_settings.view');
    }


    /*
    |--------------------------------------------------------------------------
    | Update Revenue Setting
    |--------------------------------------------------------------------------
    |
    | Allows the user to modify the global Revenue Management configuration.
    |
    | IMPORTANT:
    |
    | This permission does NOT grant permission to modify:
    |
    | - Tariff Rules
    | - Penalty Rules
    | - Interest Rules
    |
    | Those modules have their own policies and permissions.
    |
    */

    public function update(
        User $user,
        RevenueSetting $revenueSetting
    ): bool {
        return $user->can('revenue_settings.update');
    }
}