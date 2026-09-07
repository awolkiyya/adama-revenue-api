<?php

namespace App\Policies;

use App\Models\InterestRule;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class InterestRulePolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY INTEREST RULES
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.view
     *
     * Interest rules are global revenue configuration.
     *
     * Therefore, no CITY/SUBCITY/WEREDA/SECTOR or
     * administrative-unit scope restriction applies.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'interest_rules.view'
        );
    }

    /**
     * ============================================================
     * VIEW INTEREST RULE
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.view
     *
     * Interest rules are globally managed configuration.
     */
    public function view(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.view'
        );
    }

    /**
     * ============================================================
     * CREATE INTEREST RULE
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.create
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'interest_rules.create'
        );
    }

    /**
     * ============================================================
     * UPDATE INTEREST RULE
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.update
     */
    public function update(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.update'
        );
    }

    /**
     * ============================================================
     * ACTIVATE INTEREST RULE
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.activate
     *
     * Activation is intentionally separate from general update
     * because it changes whether the rule participates in the
     * active revenue configuration.
     */
    public function activate(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.activate'
        );
    }

    /**
     * ============================================================
     * DEACTIVATE INTEREST RULE
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.deactivate
     *
     * Deactivation is intentionally separate from general update.
     */
    public function deactivate(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.deactivate'
        );
    }

    /**
     * ============================================================
     * VIEW INTEREST RULE HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     interest_rules.view_history
     *
     * Historical financial/legal configuration is globally
     * accessible to users who have this permission.
     */
    public function viewHistory(
        User $user,
        InterestRule $interestRule
    ): bool {
        return $this->hasPermission(
            $user,
            'interest_rules.view_history'
        );
    }
}