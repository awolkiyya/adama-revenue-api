<?php

namespace App\Policies;

use App\Models\PenaltyRule;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class PenaltyRulePolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY PENALTY RULES
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.view
     *
     * Allows users with the permission to access the penalty
     * rule listing.
     *
     * Global permission check only.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'penalty_rules.view'
        );
    }

    /**
     * ============================================================
     * VIEW PENALTY RULE
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.view
     *
     * Penalty rules may be global or service-specific.
     *
     * The hierarchy check is applied only when the rule is
     * associated with a revenue service that belongs to an
     * organizational scope.
     */
    public function view(
        User $user,
        PenaltyRule $penaltyRule
    ): bool {
        if (! $this->hasPermission(
            $user,
            'penalty_rules.view'
        )) {
            return false;
        }

        return $this->canAccessPenaltyRule(
            $user,
            $penaltyRule
        );
    }

    /**
     * ============================================================
     * CREATE PENALTY RULE
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.create
     *
     * Creation authorization is permission based.
     *
     * Service/hierarchy validation should be handled by the
     * FormRequest and service layer.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'penalty_rules.create'
        );
    }

    /**
     * ============================================================
     * UPDATE PENALTY RULE
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.update
     *
     * The user must have the update permission and must be
     * allowed to access the organizational scope of the rule.
     */
    public function update(
        User $user,
        PenaltyRule $penaltyRule
    ): bool {
        $hasPermission = $this->hasPermission(
            $user,
            'penalty_rules.update'
        );
    
        if (! $hasPermission) {
            \Log::warning('Penalty rule update denied: missing permission', [
                'user_id' => $user->id,
                'penalty_rule_id' => $penaltyRule->id,
                'permission' => 'penalty_rules.update',
            ]);
    
            return false;
        }
    
        $hasHierarchyAccess = $this->canAccessPenaltyRule(
            $user,
            $penaltyRule
        );
    
        if (! $hasHierarchyAccess) {
            \Log::warning('Penalty rule update denied: hierarchy access', [
                'user_id' => $user->id,
                'penalty_rule_id' => $penaltyRule->id,
                'revenue_service_id' => $penaltyRule->revenue_service_id,
            ]);
        }
    
        return $hasHierarchyAccess;
    }

    /**
     * ============================================================
     * ACTIVATE PENALTY RULE
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.activate
     *
     * Activation is a configuration-level administrative action.
     */
    public function activate(
        User $user,
        PenaltyRule $penaltyRule
    ): bool {
        if (! $this->hasPermission(
            $user,
            'penalty_rules.activate'
        )) {
            return false;
        }

        return $this->canAccessPenaltyRule(
            $user,
            $penaltyRule
        );
    }

    /**
     * ============================================================
     * DEACTIVATE PENALTY RULE
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.deactivate
     *
     * Deactivation is also restricted to the user's permitted
     * organizational scope.
     */
    public function deactivate(
        User $user,
        PenaltyRule $penaltyRule
    ): bool {
        if (! $this->hasPermission(
            $user,
            'penalty_rules.deactivate'
        )) {
            return false;
        }

        return $this->canAccessPenaltyRule(
            $user,
            $penaltyRule
        );
    }

    /**
     * ============================================================
     * VIEW PENALTY RULE HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     penalty_rules.view_history
     *
     * History access requires both the history permission and
     * access to the rule's organizational scope.
     */
    public function viewHistory(
        User $user,
        PenaltyRule $penaltyRule
    ): bool {
        if (! $this->hasPermission(
            $user,
            'penalty_rules.view_history'
        )) {
            return false;
        }

        return $this->canAccessPenaltyRule(
            $user,
            $penaltyRule
        );
    }

    /**
     * ============================================================
     * PENALTY RULE HIERARCHY ACCESS
     * ============================================================
     *
     * Global penalty rules:
     *
     *     revenue_service_id = NULL
     *
     * are accessible to users who have the required permission.
     *
     * Service-specific penalty rules:
     *
     *     revenue_service_id != NULL
     *
     * are checked against the organizational hierarchy of the
     * associated revenue service.
     */
    private function canAccessPenaltyRule(
        User $user,
        PenaltyRule $penaltyRule
    ): bool {
        /*
         * Global/default penalty rule.
         *
         * There is no service hierarchy to check.
         */
        if ($penaltyRule->revenue_service_id === null) {
            return true;
        }

        /*
         * Service-specific rule.
         *
         * The RevenueService should expose the organizational
         * relationship required by ChecksHierarchy.
         */
        $penaltyRule->loadMissing('revenueService');

        if (! $penaltyRule->revenueService) {
            return false;
        }

        /*
         * Delegate the actual hierarchy decision to the shared
         * ChecksHierarchy trait.
         *
         * Replace `canAccess` with the actual hierarchy method
         * exposed by your ChecksHierarchy trait if its method has
         * a different name.
         */
        return $this->canAccess(
            $user,
            $penaltyRule->revenueService
        );
    }
}