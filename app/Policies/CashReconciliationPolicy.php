<?php

namespace App\Policies;

use App\Models\CashReconciliation;
use App\Models\User;

class CashReconciliationPolicy
{
    /**
     * Determine whether the user can view the reconciliation list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('cash_reconciliations.view')
            || $user->can('cash_reconciliations.view_all');
    }

    /**
     * Determine whether the user can view a specific reconciliation.
     */
    public function view(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        $hasViewPermission =
            $user->can('cash_reconciliations.view')
            || $user->can('cash_reconciliations.view_all');

        if (! $hasViewPermission) {
            return false;
        }

        return $this->canAccessRecord($user, $cashReconciliation);
    }

    /**
     * Determine whether the user can create a reconciliation.
     */
    public function create(User $user): bool
    {
        return $user->can('cash_reconciliations.create');
    }

    /**
     * Determine whether the user can update a reconciliation.
     *
     * Only the owning collector may update a draft or returned record.
     */
    public function update(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        if (! $user->can('cash_reconciliations.update')) {
            return false;
        }

        if (! $this->isOwner($user, $cashReconciliation)) {
            return false;
        }

        return $cashReconciliation->isEditable();
    }

    /**
     * Determine whether the user can submit a reconciliation.
     *
     * Only the owning collector may submit a draft or returned record.
     */
    public function submit(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        if (! $user->can('cash_reconciliations.submit')) {
            return false;
        }

        if (! $this->isOwner($user, $cashReconciliation)) {
            return false;
        }

        return $cashReconciliation->isEditable();
    }

    /**
     * Determine whether the user can review a reconciliation.
     *
     * A collector cannot review their own reconciliation.
     * Only submitted records awaiting review or discrepancy resolution
     * can be reviewed.
     */
    public function review(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        if (! $user->can('cash_reconciliations.review')) {
            return false;
        }

        if ($this->isOwner($user, $cashReconciliation)) {
            return false;
        }

        return in_array(
            $cashReconciliation->status,
            [
                CashReconciliation::STATUS_PENDING_REVIEW,
                CashReconciliation::STATUS_DISCREPANCY,
            ],
            true
        );
    }

    /**
     * Determine whether the user can cancel a reconciliation.
     *
     * Only the owning collector may cancel a draft or returned record.
     */
    public function cancel(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        if (! $user->can('cash_reconciliations.cancel')) {
            return false;
        }

        if (! $this->isOwner($user, $cashReconciliation)) {
            return false;
        }

        return $cashReconciliation->isEditable();
    }

    /*
    |--------------------------------------------------------------------------
    | Private Authorization Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the user can access a particular record.
     *
     * Users with view_all can access records from any collector.
     * Other users can access only their own records.
     */
    private function canAccessRecord(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        if ($user->can('cash_reconciliations.view_all')) {
            return true;
        }

        return $this->isOwner($user, $cashReconciliation);
    }

    /**
     * Determine whether the reconciliation belongs to the user.
     *
     * String comparison supports UUID identifiers.
     */
    private function isOwner(
        User $user,
        CashReconciliation $cashReconciliation
    ): bool {
        return (string) $cashReconciliation->collector_id
            === (string) $user->getKey();
    }
}

