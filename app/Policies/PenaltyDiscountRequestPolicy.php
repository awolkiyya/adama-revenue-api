<?php

namespace App\Policies;

use App\Models\PenaltyDiscountRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class PenaltyDiscountRequestPolicy
{
    use ChecksHierarchy;

    /**
     * Workflow statuses.
     *
     * Keep these synchronized with the status values stored
     * in penalty_discount_requests.
     */
    private const DRAFT = 'DRAFT';
    private const SUBMITTED = 'SUBMITTED';
    private const APPROVED = 'APPROVED';
    private const REJECTED = 'REJECTED';
    private const APPLIED = 'APPLIED';
    private const CANCELLED = 'CANCELLED';

    /**
     * ============================================================
     * VIEW ANY PENALTY DISCOUNT REQUESTS
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.read
     *
     * Allows listing and retrieving penalty discount requests.
     *
     * The `view` permission is intended for accessing the
     * management interface, while `read` controls access
     * to the actual request data.
     *
     * Organizational scope must also be enforced by the
     * query or service layer where applicable.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.read'
        );
    }

    /**
     * ============================================================
     * VIEW PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.read
     *
     * Allows retrieving an individual request and its details.
     */
    public function view(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.read'
        );
    }

    /**
     * ============================================================
     * CREATE PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.create
     *
     * Allows creating a request for an eligible invoice.
     *
     * The service layer must validate invoice eligibility,
     * the taxpayer, the outstanding penalty and the requested
     * discount amount.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.create'
        );
    }

    /**
     * ============================================================
     * UPDATE PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.create
     *
     * The current permission catalog has no separate update
     * permission, so the create permission is reused.
     *
     * Only draft requests may be updated.
     */
    public function update(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.create'
        )
            && $penaltyDiscountRequest->status === self::DRAFT
            && ! $penaltyDiscountRequest->applied_to_invoice
            && $penaltyDiscountRequest->applied_at === null;
    }

    /**
     * ============================================================
     * SUBMIT PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.submit
     *
     * Only draft requests may be submitted for administrative
     * review.
     *
     * The service layer must validate request completeness,
     * invoice eligibility and the requested amount.
     */
    public function submit(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.submit'
        )
            && $penaltyDiscountRequest->status === self::DRAFT
            && ! $penaltyDiscountRequest->applied_to_invoice
            && $penaltyDiscountRequest->applied_at === null;
    }

    /**
     * ============================================================
     * DECIDE PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.decide
     *
     * Allows an authorized officer to approve or reject
     * a submitted request.
     *
     * Only requests in SUBMITTED status may be decided.
     *
     * The service layer must validate the decision, decision
     * reason and approved amount. The approved amount must not
     * exceed the eligible penalty amount.
     */
    public function decide(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.decide'
        )
            && $penaltyDiscountRequest->status === self::SUBMITTED
            && ! $penaltyDiscountRequest->applied_to_invoice
            && $penaltyDiscountRequest->applied_at === null;
    }

    /**
     * ============================================================
     * APPROVE PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Uses the shared `decide` permission.
     *
     * Only submitted requests may be approved.
     */
    public function approve(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->decide(
            $user,
            $penaltyDiscountRequest
        );
    }

    /**
     * ============================================================
     * REJECT PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Uses the shared `decide` permission.
     *
     * Only submitted requests may be rejected.
     */
    public function reject(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->decide(
            $user,
            $penaltyDiscountRequest
        );
    }

    /**
     * ============================================================
     * APPLY APPROVED PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.apply
     *
     * Only approved requests that have not already been applied
     * may be applied to an invoice.
     *
     * The service layer must apply the discount transactionally,
     * prevent duplicate application and preserve invoice
     * financial consistency.
     */
    public function apply(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.apply'
        )
            && $penaltyDiscountRequest->status === self::APPROVED
            && ! $penaltyDiscountRequest->applied_to_invoice
            && $penaltyDiscountRequest->applied_at === null;
    }

    /**
     * ============================================================
     * CANCEL PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.cancel
     *
     * Draft, submitted and approved requests may be cancelled
     * if the discount has not already been applied.
     *
     * Rejected, applied and already cancelled requests cannot
     * be cancelled again through this operation.
     */
    public function cancel(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.cancel'
        )
            && in_array(
                $penaltyDiscountRequest->status,
                [
                    self::DRAFT,
                    self::SUBMITTED,
                    self::APPROVED,
                ],
                true
            )
            && ! $penaltyDiscountRequest->applied_to_invoice
            && $penaltyDiscountRequest->applied_at === null;
    }

    /**
     * ============================================================
     * VIEW PENALTY DISCOUNT REQUEST HISTORY
     * ============================================================
     *
     * Permission:
     *     penalty_discount_requests.view_history
     *
     * Allows viewing the request's audit and workflow history.
     */
    public function viewHistory(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.view_history'
        );
    }

    /**
     * ============================================================
     * PERMISSION CHECK
     * ============================================================
     *
     * Centralized Spatie permission check for the api guard.
     *
     * Requires the User model to use Spatie's HasRoles trait.
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

