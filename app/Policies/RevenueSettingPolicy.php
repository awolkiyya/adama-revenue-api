<?php

namespace App\Policies;

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
    | View Revenue Settings
    |--------------------------------------------------------------------------
    |
    | This is intentionally class-level authorization because the resource
    | is a global singleton configuration.
    |
    */

    public function view(User $user): bool
    {
        return $user->can('revenue_settings.view');
    }


    /*
    |--------------------------------------------------------------------------
    | Update Revenue Settings
    |--------------------------------------------------------------------------
    |
    | This is intentionally class-level authorization.
    |
    | The save endpoint can:
    |
    | - create the initial configuration
    | - update the existing configuration
    |
    | Therefore, an existing RevenueSetting model cannot be required
    | as a policy argument.
    |
    */

    public function update(User $user): bool
    {
        return $user->can('revenue_settings.update');
    }
}