<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class AssessmentPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * READ ANY ASSESSMENTS
     * ============================================================
     *
     * Permission:
     *
     *     assessment.read
     *
     * Allows retrieving and listing assessment records.
     *
     * The policy is responsible for authorization.
     * The AssessmentService remains responsible for querying,
     * filtering, pagination, and loading related data.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'assessment.read'
        );
    }

    /**
     * ============================================================
     * READ ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.read
     *
     * Allows retrieving an individual assessment.
     *
     * The policy only determines whether the user is authorized
     * to read the assessment.
     *
     * The AssessmentService remains responsible for loading the
     * assessment and its related data.
     */
    public function view(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.read'
        );
    }

    /**
     * ============================================================
     * CREATE ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.create
     *
     * Allows creating a new revenue assessment.
     *
     * The request/service layer is responsible for validating:
     *
     * - taxpayer
     * - revenue services
     * - dynamic service fields
     * - tariff calculation
     * - assessment amounts
     * - applicable financial rules
     * - due-date calculation
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'assessment.create'
        );
    }

    /**
     * ============================================================
     * REGISTER EXISTING AGREEMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.register_existing
     *
     * Allows registering an existing revenue agreement and
     * continuing its outstanding financial obligation from the
     * current balance.
     *
     * The business/service layer remains responsible for:
     *
     * - validating the existing agreement
     * - determining outstanding balance
     * - preserving financial history
     * - creating the assessment
     * - applying applicable financial rules
     */
    public function registerExisting(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'assessment.register_existing'
        );
    }

    /**
     * ============================================================
     * UPDATE ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.update
     *
     * Allows updating an assessment.
     *
     * The AssessmentService must additionally enforce whether
     * the assessment is actually editable according to its
     * lifecycle state.
     *
     * Typically editable:
     *
     *     DRAFT
     *     RETURNED
     *
     * Typically not editable:
     *
     *     APPROVED
     *     CANCELLED
     */
    public function update(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.update'
        );
    }

    /**
     * ============================================================
     * DELETE ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.delete
     *
     * Allows deleting an assessment.
     *
     * The AssessmentService must still verify that the current
     * lifecycle state allows deletion.
     *
     * Normally, only eligible draft assessments should be
     * deletable.
     */
    public function delete(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.delete'
        );
    }

    /**
     * ============================================================
     * SUBMIT ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.submit
     *
     * Allows submitting an assessment for decision.
     *
     * The business/service layer must enforce the valid
     * lifecycle transition, for example:
     *
     *     DRAFT
     *       ↓
     * PENDING_APPROVAL
     *
     * The policy only determines whether the user has permission
     * to perform the submission action.
     */
    public function submit(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.submit'
        );
    }

    /**
     * ============================================================
     * VERIFY ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.verify
     *
     * Allows verifying assessment details.
     *
     * The verification service/business layer remains responsible
     * for validating the assessment data and enforcing the
     * appropriate lifecycle transition.
     */
    public function verify(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.verify'
        );
    }

    /**
     * ============================================================
     * APPROVE ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.approve
     *
     * Allows approving an assessment.
     *
     * AssessmentApprovalService remains responsible for:
     *
     * - validating PENDING_APPROVAL status
     * - recording approved_by
     * - recording approved_at
     * - creating invoice
     * - creating invoice items
     * - calculating invoice totals
     * - issuing the invoice
     * - recording issued_by
     * - recording issued_at
     * - triggering taxpayer notification
     *
     * The policy only answers:
     *
     *     "Is this user authorized to approve?"
     */
    public function approve(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.approve'
        );
    }

    /**
     * ============================================================
     * RETURN ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.return
     *
     * Allows returning an assessment for correction.
     *
     * The method is intentionally named returnAssessment instead
     * of return to avoid using a PHP language construct as the
     * policy method name.
     *
     * The AssessmentService must enforce the valid transition:
     *
     *     PENDING_APPROVAL
     *             ↓
     *          RETURNED
     */
    public function returnAssessment(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.return'
        );
    }

    /**
     * ============================================================
     * REJECT ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.reject
     *
     * Allows rejecting an assessment when the business workflow
     * supports a distinct REJECTED state.
     *
     * The service layer must enforce:
     *
     * - whether rejection is allowed from the current state
     * - the resulting lifecycle state
     * - rejection reason requirements
     * - audit/history recording
     */
    public function reject(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.reject'
        );
    }

    /**
     * ============================================================
     * CANCEL ASSESSMENT
     * ============================================================
     *
     * Permission:
     *
     *     assessment.cancel
     *
     * Allows cancelling an assessment.
     *
     * The AssessmentService must still enforce the allowed
     * lifecycle transitions and cancellation rules.
     */
    public function cancel(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.cancel'
        );
    }

    /**
     * ============================================================
     * VIEW ASSESSMENT HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     assessment.view_history
     *
     * Allows viewing the assessment's historical records and
     * decision history.
     *
     * This remains separate from assessment.read because history
     * may contain sensitive decision and audit information.
     */
    public function viewHistory(
        User $user,
        Assessment $assessment
    ): bool {
        return $this->hasPermission(
            $user,
            'assessment.view_history'
        );
    }
}
