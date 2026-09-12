<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use App\Models\PenaltyRule;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AssessmentCalculationService
{
    public function __construct(
        private readonly TariffResolver $resolver,
        private readonly TariffCalculator $calculator,
        private readonly \App\Services\Financial\DueDateResolver $dueDateResolver,
    ) {
    }

    /**
     * ========================================================================
     * CALCULATE ASSESSMENT
     * ========================================================================
     *
     * Calculates every revenue service belonging to the assessment.
     *
     * IMPORTANT:
     * ------------------------------------------------------------------------
     * This service performs INITIAL FINANCIAL CALCULATION ONLY.
     *
     * It is responsible for:
     *
     * - resolving the applicable tariff version
     * - resolving the applicable tariff rule
     * - calculating the original principal amount
     * - resolving the applicable penalty rule
     * - resolving the applicable interest rule
     * - resolving the payment due date
     * - storing the initial financial result
     *
     * It does NOT:
     *
     * - approve the assessment
     * - reject the assessment
     * - create an invoice
     * - collect payment
     * - calculate accrued penalty
     * - calculate accrued interest
     * - calculate outstanding balance
     *
     * Ongoing penalty, interest and outstanding-balance calculation
     * belongs to the dedicated financial services used by the scheduler.
     *
     * Expected lifecycle:
     *
     *     PENDING_APPROVAL
     *          |
     *          | calculate
     *          v
     *     PROCESSING
     *          |
     *          +--------------------+
     *          |                    |
     *          v                    v
     *     COMPLETED              ERROR
     *          |
     *          v
     *     PENDING_APPROVAL
     *          |
     *          +--------------------+
     *          |                    |
     *          v                    v
     *       APPROVED             RETURNED
     *
     * The decision maker sees the calculated principal amount while
     * the assessment is still PENDING_APPROVAL.
     *
     * Approval does NOT trigger this calculation.
     */
    public function calculate(
        Assessment $assessment,
    ): Assessment {

        /*
        |--------------------------------------------------------------------------
        | Validate assessment state
        |--------------------------------------------------------------------------
        */

        if ($assessment->status !== 'PENDING_APPROVAL') {
            throw new RuntimeException(
                sprintf(
                    'Assessment %s cannot be calculated because its status is %s. Calculation is only allowed for PENDING_APPROVAL assessments.',
                    $assessment->id,
                    $assessment->status,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate inside one database transaction
        |--------------------------------------------------------------------------
        |
        | The entire assessment calculation is atomic.
        |
        | If any service fails:
        |
        | - all calculations are rolled back
        | - the failure is recorded afterward
        |
        */

        try {

            return DB::transaction(
                function () use ($assessment) {

                    /*
                    |--------------------------------------------------------------------------
                    | Lock assessment
                    |--------------------------------------------------------------------------
                    |
                    | Prevent concurrent calculation of the same assessment.
                    |
                    */

                    $lockedAssessment =
                        Assessment::query()
                            ->whereKey($assessment->id)
                            ->lockForUpdate()
                            ->first();

                    if (!$lockedAssessment) {
                        throw new RuntimeException(
                            sprintf(
                                'Assessment %s was not found.',
                                $assessment->id,
                            )
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Verify state again after locking
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $lockedAssessment->status !==
                        'PENDING_APPROVAL'
                    ) {
                        throw new RuntimeException(
                            sprintf(
                                'Assessment %s cannot be calculated because its status is %s.',
                                $lockedAssessment->id,
                                $lockedAssessment->status,
                            )
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Load calculation data
                    |--------------------------------------------------------------------------
                    */

                    $lockedAssessment->load([
                        'services.values',
                        'services.service',
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Validate services
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $lockedAssessment->services->isEmpty()
                    ) {
                        throw new RuntimeException(
                            'Cannot calculate an assessment without revenue services.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Calculate every service
                    |--------------------------------------------------------------------------
                    */

                    foreach (
                        $lockedAssessment->services
                        as $assessmentService
                    ) {

                        $this->calculateService(
                            $assessmentService
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Verify every service completed
                    |--------------------------------------------------------------------------
                    */

                    $hasIncompleteServices =
                        $lockedAssessment
                            ->services()
                            ->where(
                                'status',
                                '!=',
                                'COMPLETED',
                            )
                            ->exists();

                    if ($hasIncompleteServices) {
                        throw new RuntimeException(
                            'Assessment calculation failed because one or more services were not completed.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Keep assessment PENDING_APPROVAL
                    |--------------------------------------------------------------------------
                    |
                    | Calculation does NOT mean approval.
                    |
                    | The calculated principal, due date and applicable
                    | financial rules are now ready for the decision maker.
                    |
                    */

                    $lockedAssessment->update([
                        'status' => 'PENDING_APPROVAL',
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Return fresh assessment
                    |--------------------------------------------------------------------------
                    */

                    return $lockedAssessment->fresh([
                        'services.values',
                        'services.service',
                        'services.penaltyRule',
                        'services.interestRule',
                    ]);
                },
            );

        } catch (Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | Transaction has already rolled back
            |--------------------------------------------------------------------------
            |
            | Persist the failure separately so the operator can see
            | that calculation failed.
            |
            */

            $this->recordCalculationFailure(
                $assessment,
                $exception,
            );

            throw $exception;
        }
    }

    /**
     * ========================================================================
     * CALCULATE ONE ASSESSMENT SERVICE
     * ========================================================================
     *
     * Complete initial financial calculation:
     *
     *     Tariff Version
     *          ↓
     *     Tariff Rule
     *          ↓
     *     Principal
     *          ↓
     *     Penalty Rule
     *          ↓
     *     Interest Rule
     *          ↓
     *     Due Date
     *          ↓
     *     Persist
     */
    private function calculateService(
        AssessmentService $assessmentService,
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Mark service as PROCESSING
        |--------------------------------------------------------------------------
        |
        | Reset previous calculation results.
        |
        | IMPORTANT:
        |
        | computed_amount is the ORIGINAL PRINCIPAL.
        |
        | Penalty and interest are NOT added to this field.
        |
        */

        $assessmentService->update([
            'status' => 'PROCESSING',

            'computed_amount' => null,

            'currency_code' => null,

            'due_date' => null,

            'penalty_rule_id' => null,

            'interest_rule_id' => null,

            'calculation_metadata' => null,

            'calculation_error' => null,

            'calculated_at' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Resolve tariff version
        |--------------------------------------------------------------------------
        */

        $version =
            $this->resolver
                ->resolveVersion(
                    $assessmentService,
                );

        /*
        |--------------------------------------------------------------------------
        | Resolve tariff rule
        |--------------------------------------------------------------------------
        */

        $rule =
            $this->resolver
                ->resolveRule(
                    $version,
                    $assessmentService,
                );

        /*
        |--------------------------------------------------------------------------
        | Calculate original principal
        |--------------------------------------------------------------------------
        */

        $result =
            $this->calculator
                ->calculate(
                    $rule,
                    $assessmentService,
                );

        /*
        |--------------------------------------------------------------------------
        | Handle principal calculation failure
        |--------------------------------------------------------------------------
        */

        if (!$result->isSuccessful()) {

            throw new RuntimeException(
                sprintf(
                    'Calculation failed for service %s: %s',
                    $assessmentService->id,
                    $result->error
                        ?? 'Unknown calculation error.',
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Penalty Rule
        |--------------------------------------------------------------------------
        |
        | TariffResolver is responsible for determining which penalty
        | rule applies to this assessment service.
        |
        | The selected rule is then passed to DueDateResolver.
        |
        */

        $penaltyRule =
            $this->resolver
                ->resolvePenaltyRule(
                    $assessmentService,
                );

        if (!$penaltyRule instanceof PenaltyRule) {
            throw new RuntimeException(
                sprintf(
                    'No valid penalty rule could be resolved for assessment service %s.',
                    $assessmentService->id,
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Interest Rule
        |--------------------------------------------------------------------------
        |
        | Interest rule selection is kept separate from due-date
        | calculation.
        |
        | The InterestRule is stored on assessment_services so that
        | future calculations use the exact rule selected at the time
        | of assessment calculation.
        |
        */

        $interestRule =
            $this->resolver
                ->resolveInterestRule(
                    $assessmentService,
                );

        /*
        |--------------------------------------------------------------------------
        | Resolve Due Date
        |--------------------------------------------------------------------------
        |
        | DueDateResolver receives the already-selected PenaltyRule.
        |
        | It determines the base date and applies the rule's
        | configured due-date offset.
        |
        */

        $dueDate =
            $this->dueDateResolver
                ->resolve(
                    $assessmentService,
                    $penaltyRule,
                );

        /*
        |--------------------------------------------------------------------------
        | Build calculation metadata
        |--------------------------------------------------------------------------
        |
        | tariff_version_id and tariff_rule_id are not dedicated columns
        | on assessment_services.
        |
        | They therefore remain in calculation_metadata as an audit
        | snapshot of the calculation.
        |
        */

        $metadata = array_merge(
            [
                'tariff_version_id' =>
                    $version->id,

                'tariff_rule_id' =>
                    $rule->id,

                'calculation_type' =>
                    $rule->calculation_type,

                'penalty_rule_id' =>
                    $penaltyRule->id,

                'interest_rule_id' =>
                    $interestRule?->id,

                'due_date' =>
                    $dueDate->toDateString(),

                'calculated_at' =>
                    now()->toISOString(),
            ],
            $result->metadata ?? [],
        );

        /*
        |--------------------------------------------------------------------------
        | Persist successful calculation
        |--------------------------------------------------------------------------
        |
        | computed_amount:
        |     Original principal only.
        |
        | due_date:
        |     Final payment deadline.
        |
        | penalty_rule_id:
        |     Exact penalty rule selected for this obligation.
        |
        | interest_rule_id:
        |     Exact interest rule selected for this obligation.
        |
        */

        $assessmentService->update([
            'status' =>
                'COMPLETED',

            'computed_amount' =>
                $result->amount,

            'currency_code' =>
                $result->currencyCode
                ?? $this->resolveCurrency(
                    $version
                ),

            'due_date' =>
                $dueDate->toDateString(),

            'penalty_rule_id' =>
                $penaltyRule->id,

            'interest_rule_id' =>
                $interestRule?->id,

            'calculation_metadata' =>
                $metadata,

            'calculation_error' =>
                null,

            'calculated_at' =>
                now(),
        ]);
    }

    /**
     * ========================================================================
     * RECORD CALCULATION FAILURE
     * ========================================================================
     *
     * This method executes after the main calculation transaction has
     * rolled back.
     *
     * The error state is intentionally persisted separately.
     */
    private function recordCalculationFailure(
        Assessment $assessment,
        Throwable $exception,
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Reload current database state
        |--------------------------------------------------------------------------
        */

        $assessment->load([
            'services',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Determine error message
        |--------------------------------------------------------------------------
        */

        $errorMessage =
            $exception->getMessage()
            ?: 'Assessment calculation failed.';

        /*
        |--------------------------------------------------------------------------
        | Mark affected services as ERROR
        |--------------------------------------------------------------------------
        |
        | Clear all calculated financial data so an old calculation
        | cannot remain visible together with ERROR.
        |
        */

        foreach (
            $assessment->services
            as $assessmentService
        ) {

            if (
                $assessmentService->status ===
                'COMPLETED'
            ) {
                continue;
            }

            $assessmentService->update([
                'status' =>
                    'ERROR',

                'computed_amount' =>
                    null,

                'currency_code' =>
                    null,

                'due_date' =>
                    null,

                'penalty_rule_id' =>
                    null,

                'interest_rule_id' =>
                    null,

                'calculation_metadata' =>
                    null,

                'calculation_error' =>
                    $errorMessage,

                'calculated_at' =>
                    now(),
            ]);
        }
    }

    /**
     * ========================================================================
     * RESOLVE CURRENCY
     * ========================================================================
     *
     * Recommended source:
     *
     *     tariff_versions.currency_code
     *
     * If currency_code does not exist on tariff_versions,
     * this safely returns null.
     */
    private function resolveCurrency(
        object $version,
    ): ?string {

        return $version->currency_code ?? null;
    }
}

