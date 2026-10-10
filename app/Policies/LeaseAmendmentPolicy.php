<?php

namespace App\Policies;

use App\Models\LeaseAmendment;
use App\Models\User;

class LeaseAmendmentPolicy
{
    /**
     * Workflow statuses used by the policy.
     * Keep these in sync with the lease_amendments.status values
     * returned by the API (and used by the frontend).
     */
    private const DRAFT = 'DRAFT';
    private const PENDING_APPROVAL = 'PENDING_APPROVAL';
    private const APPROVED = 'APPROVED';

    /**
     * =========================================================
     * VIEW ANY LEASE AMENDMENTS
     * =========================================================
     *
     * Permission: lease_amendments.read
     *
     * Determines whether the user can retrieve or list
     * lease amendment records.
     *
     * lease_amendments.view is reserved for accessing
     * the Lease Amendment Management interface.
     *
     * The controller/query must still enforce organizational
     * scope and any applicable data-access restrictions.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'lease_amendments.read'
        );
    }

    /**
     * =========================================================
     * VIEW LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.read
     *
     * Determines whether the user can retrieve an individual
     * lease amendment and its details.
     */
    public function view(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.read'
        );
    }

    /**
     * =========================================================
     * CREATE LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.create
     *
     * The service/controller must additionally validate that
     * the original lease or assessment is eligible for
     * amendment.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'lease_amendments.create'
        );
    }

    /**
     * =========================================================
     * UPDATE LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.update
     *
     * Only draft amendments are editable.
     *
     * The service must enforce this workflow restriction
     * independently of the policy.
     */
    public function update(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.update'
        ) && $leaseAmendment->status === self::DRAFT;
    }

    /**
     * =========================================================
     * SUBMIT LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.submit
     *
     * Only draft amendments may be submitted.
     */
    public function submit(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.submit'
        ) && $leaseAmendment->status === self::DRAFT;
    }

    /**
     * =========================================================
     * APPROVE LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.approve
     *
     * Only amendments awaiting approval (PENDING_APPROVAL)
     * may be approved.
     */
    public function approve(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.approve'
        ) && $leaseAmendment->status === self::PENDING_APPROVAL;
    }

    /**
     * =========================================================
     * REJECT LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.reject
     *
     * Only amendments awaiting approval (PENDING_APPROVAL)
     * may be rejected.
     */
    public function reject(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.reject'
        ) && $leaseAmendment->status === self::PENDING_APPROVAL;
    }

    /**
     * =========================================================
     * APPLY LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.apply
     *
     * Only approved amendments may be applied.
     *
     * The application service must validate the replacement
     * assessment, financial schedules, existing balances,
     * and transactional consistency before applying changes.
     */
    public function apply(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.apply'
        ) && $leaseAmendment->status === self::APPROVED;
    }

    /**
     * =========================================================
     * CANCEL LEASE AMENDMENT
     * =========================================================
     *
     * Permission: lease_amendments.cancel
     *
     * Draft, pending-approval and approved amendments may be
     * cancelled.
     *
     * Applied amendments cannot be cancelled through this
     * operation. Financially effective changes require a
     * separate controlled correction or reversal process.
     */
    public function cancel(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.cancel'
        ) && in_array(
            $leaseAmendment->status,
            [
                self::DRAFT,
                self::PENDING_APPROVAL,
                self::APPROVED,
            ],
            true
        );
    }

    /**
     * =========================================================
     * VIEW LEASE AMENDMENT HISTORY
     * =========================================================
     *
     * Permission: lease_amendments.view_history
     */
    public function viewHistory(
        User $user,
        LeaseAmendment $leaseAmendment
    ): bool {
        return $this->hasPermission(
            $user,
            'lease_amendments.view_history'
        );
    }

    /**
     * =========================================================
     * PERMISSION CHECK
     * =========================================================
     *
     * Centralized permission check for the api guard.
     *
     * This assumes the User model uses Spatie's
     * HasRoles trait and the permission guard is api.
     */
    protected function hasPermission(
        User $user,
        string $permission
    ): bool {
        return $user->hasPermissionTo(
            $permission,
            'api'
        );
    }
}