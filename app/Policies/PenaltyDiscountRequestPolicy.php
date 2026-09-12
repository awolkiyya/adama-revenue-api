<?php

namespace App\Policies;

use App\Models\PenaltyDiscountRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class PenaltyDiscountRequestPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY PENALTY DISCOUNT REQUESTS
     * ============================================================
     *
     * Permission:
     *
     *     penalty_discount_requests.read
     *
     * Allows retrieving and listing penalty discount requests.
     *
     * The `view` permission is intended for accessing the
     * Penalty Discount Request Management interface, while
     * `read` controls access to the actual request data.
     *
     * Organizational scope should be enforced by the query/service
     * layer where applicable.
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
     *
     *     penalty_discount_requests.read
     *
     * Allows retrieving an individual penalty discount request,
     * including its invoice, requested amount, decision,
     * approved amount, and application status.
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
     *
     *     penalty_discount_requests.create
     *
     * Allows creating a penalty discount request for an
     * eligible invoice.
     *
     * The request is created by the Revenue Compliance Officer.
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
     * SUBMIT PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *
     *     penalty_discount_requests.submit
     *
     * Allows submitting a draft penalty discount request for
     * administrative review.
     *
     * The service layer must ensure that:
     *
     * - The request is in DRAFT status.
     * - The invoice is eligible.
     * - The requested amount is valid.
     * - Required information is complete.
     */
    public function submit(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.submit'
        );
    }

    /**
     * ============================================================
     * DECIDE PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *
     *     penalty_discount_requests.decide
     *
     * Allows an authorized Revenue Tax Administrative Officer
     * to make the administrative decision.
     *
     * The decision may be:
     *
     *     APPROVED
     *     REJECTED
     *
     * When approved, the officer specifies the approved
     * penalty discount amount.
     *
     * The service layer must enforce the actual business rules,
     * including ensuring that the approved amount does not
     * exceed the invoice's current penalty.
     */
    public function decide(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.decide'
        );
    }

    /**
     * ============================================================
     * CANCEL PENALTY DISCOUNT REQUEST
     * ============================================================
     *
     * Permission:
     *
     *     penalty_discount_requests.cancel
     *
     * Allows cancelling an eligible penalty discount request
     * before a final administrative decision is made.
     *
     * The service layer must prevent cancellation of requests
     * that have already been finally decided.
     */
    public function cancel(
        User $user,
        PenaltyDiscountRequest $penaltyDiscountRequest
    ): bool {
        return $this->hasPermission(
            $user,
            'penalty_discount_requests.cancel'
        );
    }

    /**
     * ============================================================
     * VIEW PENALTY DISCOUNT REQUEST HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     penalty_discount_requests.view_history
     *
     * Allows viewing the history of:
     *
     * - request creation
     * - submission
     * - administrative decision
     * - approved amount
     * - invoice application
     * - cancellation
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
}