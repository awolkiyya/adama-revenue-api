<?php

namespace App\Policies;

use App\Models\RevenueService;
use App\Models\ServiceAccessRule;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class ServiceAccessRulePolicy
{
    use ChecksHierarchy;

    /**
     * View the list of service access rules.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'service_access_rules.view'
        );
    }

    /**
     * View a single service access rule.
     */
    public function view(
        User $user,
        ServiceAccessRule $serviceAccessRule
    ): bool {
        return $this->hasPermission(
            $user,
            'service_access_rules.view'
        );
    }

    /**
     * Update a single service access rule.
     */
    public function update(
        User $user,
        ServiceAccessRule $serviceAccessRule
    ): bool {
        return $this->hasPermission(
            $user,
            'service_access_rules.update'
        );
    }

    /**
     * Activate a service access rule.
     */
    public function activate(
        User $user,
        ServiceAccessRule $serviceAccessRule
    ): bool {
        return $this->hasPermission(
            $user,
            'service_access_rules.activate'
        );
    }

    /**
     * Deactivate a service access rule.
     */
    public function deactivate(
        User $user,
        ServiceAccessRule $serviceAccessRule
    ): bool {
        return $this->hasPermission(
            $user,
            'service_access_rules.deactivate'
        );
    }

    /**
     * Synchronize all sector access rules for a revenue service.
     */
    public function sync(
        User $user,
        RevenueService $service
    ): bool {
        return $this->hasPermission(
            $user,
            'service_access_rules.update'
        );
    }
}