<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Assessment\Requests\CancelAssessmentRequest;
use App\Modules\Assessment\Requests\ReturnAssessmentRequest;
use App\Services\AssessmentCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentWorkflowService
{
    public function __construct(
        protected AssessmentCalculationService $calculationService,
    ) {
    }

    /**
     * ============================================================
     * SUBMIT
     * ============================================================
     */

    /**
     * Submit an assessment for approval.
     *
     * Allowed transitions:
     *
     * DRAFT
     *     ↓
     * PENDING_APPROVAL
     *
     * RETURNED
     *     ↓
     * PENDING_APPROVAL
     *
     * Financial calculation is mandatory.
     *
     * The status is changed to PENDING_APPROVAL BEFORE calculation
     * because AssessmentCalculationService expects the assessment
     * to be in PENDING_APPROVAL state.
     *
     * Everything happens inside one database transaction.
     *
     * If calculation fails:
     *
     *     PENDING_APPROVAL
     *          ↓
     *       ROLLBACK
     *          ↓
     *     DRAFT / RETURNED
     *
     * No partial submission is persisted.
     */
    public function submit(
        Assessment $assessment
    ): Assessment {
        return DB::transaction(function () use ($assessment) {

            /*
            |--------------------------------------------------------------------------
            | Lock Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail($assessment->id);

            /*
            |--------------------------------------------------------------------------
            | Validate Current State
            |--------------------------------------------------------------------------
            */

            if (! in_array(
                $assessment->status,
                [
                    'DRAFT',
                    'RETURNED',
                ],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only draft or returned assessments can be submitted.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Workflow State
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | This happens BEFORE calculation because the calculation
            | service expects the assessment to be PENDING_APPROVAL.
            |
            | This update is still inside the transaction.
            |
            | If calculation fails, Laravel automatically rolls back
            | this status change.
            |
            */

            $assessment->update([
                'status' => 'PENDING_APPROVAL',

                'submitted_at' => now(),

                /*
                 * Clear previous decision information when a
                 * returned assessment is resubmitted.
                 */
                'decision' => null,

                'decision_notes' => null,

                'decided_by' => null,

                'decided_at' => null,

                'approved_at' => null,

                'rejected_at' => null,

                'updated_by' => auth()->id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Calculate Financial Values
            |--------------------------------------------------------------------------
            |
            | The calculation service handles:
            |
            | - Tariff version resolution
            | - Tariff rule resolution
            | - Principal calculation
            | - Penalty rule resolution
            | - Interest rule resolution
            | - Due date resolution
            | - Assessment service financial values
            |
            | If any calculation step throws an exception,
            | DB::transaction() automatically rolls back:
            |
            | - status change
            | - submitted_at
            | - financial calculation
            | - assessment service changes
            |
            */

            $this->calculationService->calculate(
                $assessment
            );

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Assessment
            |--------------------------------------------------------------------------
            |
            | The transaction has not committed yet.
            |
            | The fresh model is loaded from the current transaction
            | so the response contains the newly calculated values.
            |
            */

            return $assessment->fresh([
                'taxpayer',
                'creator',
                'updater',
                'decisionOfficer',
                'services',
                'services.service',
                'services.values',
                'services.values.files',
                'services.penaltyRule',
                'services.interestRule',
            ]);
        });
    }

    /**
     * ============================================================
     * RETURN
     * ============================================================
     */

    /**
     * Return an assessment for correction.
     *
     * Allowed transition:
     *
     * PENDING_APPROVAL
     *     ↓
     * RETURNED
     *
     * The assessment can then be edited and submitted again.
     */
    public function returnAssessment(
        Assessment $assessment,
        ReturnAssessmentRequest $request
    ): Assessment {
        return DB::transaction(function () use (
            $assessment,
            $request
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail($assessment->id);

            /*
            |--------------------------------------------------------------------------
            | Validate Current State
            |--------------------------------------------------------------------------
            */

            if ($assessment->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only assessments pending approval can be returned.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Reason
            |--------------------------------------------------------------------------
            */

            $reason = trim(
                (string) $request->validated('reason')
            );

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [
                        'A return reason is required.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Workflow State
            |--------------------------------------------------------------------------
            */

            $assessment->update([
                'status' => 'RETURNED',

                'decision_notes' => $reason,

                'decided_by' => auth()->id(),

                'decided_at' => now(),

                'approved_at' => null,

                'rejected_at' => null,

                'decision' => null,

                'updated_by' => auth()->id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Assessment
            |--------------------------------------------------------------------------
            */

            return $assessment->fresh([
                'taxpayer',
                'creator',
                'updater',
                'decisionOfficer',
                'services',
                'services.service',
                'services.values',
                'services.values.files',
                'services.penaltyRule',
                'services.interestRule',
            ]);
        });
    }

    /**
     * ============================================================
     * CANCEL
     * ============================================================
     */

    /**
     * Cancel an assessment.
     *
     * Allowed states:
     *
     * DRAFT
     * RETURNED
     * PENDING_APPROVAL
     *
     * Transition:
     *
     * DRAFT / RETURNED / PENDING_APPROVAL
     *                 ↓
     *             CANCELLED
     */
    public function cancel(
        Assessment $assessment,
        CancelAssessmentRequest $request
    ): Assessment {
        return DB::transaction(function () use (
            $assessment,
            $request
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lock Assessment
            |--------------------------------------------------------------------------
            */

            $assessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail($assessment->id);

            /*
            |--------------------------------------------------------------------------
            | Validate Current State
            |--------------------------------------------------------------------------
            */

            $allowedStatuses = [
                'DRAFT',
                'RETURNED',
                'PENDING_APPROVAL',
            ];

            if (! in_array(
                $assessment->status,
                $allowedStatuses,
                true
            )) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This assessment cannot be cancelled in its current status.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Reason
            |--------------------------------------------------------------------------
            */

            $reason = trim(
                (string) $request->validated('reason')
            );

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [
                        'A cancellation reason is required.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Workflow State
            |--------------------------------------------------------------------------
            */

            $assessment->update([
                'status' => 'CANCELLED',

                'cancellation_reason' => $reason,

                'cancelled_at' => now(),

                'cancelled_by' => auth()->id(),

                'updated_by' => auth()->id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Assessment
            |--------------------------------------------------------------------------
            */

            return $assessment->fresh([
                'taxpayer',
                'creator',
                'updater',
                'decisionOfficer',
                'services',
                'services.service',
                'services.values',
                'services.values.files',
                'services.penaltyRule',
                'services.interestRule',
            ]);
        });
    }
}
