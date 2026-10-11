<?php

namespace App\Policies;

use App\Models\CashReconciliation;
use App\Models\User;

class CashReconciliationPolicy
{
    /**
     * =========================================================
     * VIEW ANY CASH RECONCILIATIONS
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.view
     *
     * This permits access to the reconciliation listing.
     *
     * The query must still be scoped:
     *
     * - Ordinary users: their own reconciliations.
     * - Users with view-all: all authorized reconciliations.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'cash-reconciliations.view'
        );
    }

    /**
     * =========================================================
     * VIEW CASH RECONCILIATION
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.view
     *
     * +
     *
     * Record ownership or view-all permission.
     */
    public function view(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        if (!$this->hasPermission(
            $user,
            'cash-reconciliations.view'
        )) {
            return false;
        }

        return $this->canAccessRecord(
            $user,
            $reconciliation
        );
    }

    /**
     * =========================================================
     * CREATE CASH RECONCILIATION
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.create
     *
     * The authenticated user must be assigned as collector
     * by the backend. Do not accept collector_id from the
     * request as the authoritative identity.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'cash-reconciliations.create'
        );
    }

    /**
     * =========================================================
     * UPDATE CASH RECONCILIATION
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.create
     *
     * +
     *
     * Ownership.
     *
     * The service must additionally restrict updates to
     * DRAFT or RETURNED records.
     */
    public function update(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        return $this->hasPermission(
            $user,
            'cash-reconciliations.create'
        )
            && $this->isCollector(
                $user,
                $reconciliation
            )
            && in_array(
                $reconciliation->status,
                [
                    CashReconciliation::STATUS_DRAFT,
                    CashReconciliation::STATUS_RETURNED,
                ],
                true
            );
    }

    /**
     * =========================================================
     * SUBMIT CASH RECONCILIATION
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.submit
     *
     * +
     *
     * Ownership and editable status.
     *
     * Expected amount and difference must be calculated
     * by the backend during submission.
     */
    public function submit(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        return $this->hasPermission(
            $user,
            'cash-reconciliations.submit'
        )
            && $this->isCollector(
                $user,
                $reconciliation
            )
            && in_array(
                $reconciliation->status,
                [
                    CashReconciliation::STATUS_DRAFT,
                    CashReconciliation::STATUS_RETURNED,
                ],
                true
            );
    }

    /**
     * =========================================================
     * REVIEW CASH RECONCILIATION
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.review
     *
     * +
     *
     * Separation of duties.
     *
     * A collector cannot review their own reconciliation.
     * Only records awaiting review or requiring discrepancy
     * resolution can be reviewed.
     */
    public function review(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        if (!$this->hasPermission(
            $user,
            'cash-reconciliations.review'
        )) {
            return false;
        }

        if ($this->isCollector(
            $user,
            $reconciliation
        )) {
            return false;
        }

        return in_array(
            $reconciliation->status,
            [
                CashReconciliation::STATUS_PENDING_REVIEW,
                CashReconciliation::STATUS_DISCREPANCY,
            ],
            true
        );
    }

    /**
     * =========================================================
     * CANCEL CASH RECONCILIATION
     * =========================================================
     *
     * Permission:
     *
     *     cash-reconciliations.create
     *
     * +
     *
     * Ownership and cancellable status.
     *
     * Submitted reconciliations cannot be cancelled through
     * this action.
     */
    public function cancel(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        return $this->hasPermission(
            $user,
            'cash-reconciliations.create'
        )
            && $this->isCollector(
                $user,
                $reconciliation
            )
            && in_array(
                $reconciliation->status,
                [
                    CashReconciliation::STATUS_DRAFT,
                    CashReconciliation::STATUS_RETURNED,
                ],
                true
            );
    }

    /**
     * =========================================================
     * CHECK RECORD ACCESS
     * =========================================================
     *
     * This method checks record-level access.
     *
     * It does not replace the base view permission.
     */
    protected function canAccessRecord(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        if ($this->hasPermission(
            $user,
            'cash-reconciliations.view-all'
        )) {
            return true;
        }

        return $this->isCollector(
            $user,
            $reconciliation
        );
    }

    /**
     * =========================================================
     * CHECK COLLECTOR OWNERSHIP
     * =========================================================
     */
    protected function isCollector(
        User $user,
        CashReconciliation $reconciliation
    ): bool {
        return $user->getKey() !== null
            && $reconciliation->collector_id !== null
            && (string) $user->getKey()
                === (string) $reconciliation->collector_id;
    }

    /**
     * =========================================================
     * CHECK PERMISSION
     * =========================================================
     *
     * Permission-driven authorization.
     * No role names are hard-coded.
     */
    protected function hasPermission(
        User $user,
        string $permission
    ): bool {
        return $user->can($permission);
    }
}
