<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AssessmentCalculationService
{
    public function __construct(
        private readonly TariffResolver $resolver,
        private readonly TariffCalculator $calculator,
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
     * This service performs FINANCIAL CALCULATION ONLY.
     *
     * It does NOT:
     *
     * - approve the assessment
     * - reject the assessment
     * - create an invoice
     * - collect payment
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
     *       APPROVED             REJECTED
     *
     * The decision maker sees the calculated amount while the
     * assessment is still PENDING_APPROVAL.
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
        |
        | Calculation is intended to happen before the final decision.
        |
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
        | If any service fails:
        |
        | - all successful calculations are rolled back
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
                    | Prevent two workers/officers from calculating the
                    | same assessment simultaneously.
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
                    | IMPORTANT:
                    |
                    | Calculation does NOT mean approval.
                    |
                    | The calculated financial result is now ready
                    | for the decision maker.
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
                    ]);
                },
            );

        } catch (Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | Transaction has already rolled back
            |--------------------------------------------------------------------------
            |
            | Successful calculations that happened before the failure
            | have been rolled back.
            |
            | We now persist the error state separately so the failure
            | is visible to the system/operator.
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
     */
    private function calculateService(
        AssessmentService $assessmentService,
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Mark service as PROCESSING
        |--------------------------------------------------------------------------
        */

        $assessmentService->update([
            'status' => 'PROCESSING',

            'computed_amount' => null,

            'currency_code' => null,

            'calculation_metadata' => null,

            'calculation_error' => null,

            'calculated_at' => null,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Resolve tariff version
        |--------------------------------------------------------------------------
        |
        | TariffResolver is responsible for determining the approved,
        | active tariff version applicable to the assessment date.
        |
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
        |
        | The resolver determines the rule applicable to this
        | revenue service.
        |
        */

        $rule =
            $this->resolver
                ->resolveRule(
                    $version,
                    $assessmentService,
                );


        /*
        |--------------------------------------------------------------------------
        | Execute tariff calculation
        |--------------------------------------------------------------------------
        |
        | TariffCalculator receives:
        |
        | - tariff rule
        | - assessment service
        | - captured assessment values
        |
        | and returns the calculated financial result.
        |
        */

        $result =
            $this->calculator
                ->calculate(
                    $rule,
                    $assessmentService,
                );


        /*
        |--------------------------------------------------------------------------
        | Handle calculation failure
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
        | Build calculation metadata
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Your assessment_services migration does not contain:
        |
        |     tariff_version_id
        |     tariff_rule_id
        |
        | Therefore these values are stored inside calculation_metadata.
        |
        | This creates an audit snapshot of the calculation.
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

                'calculated_at' =>
                    now()->toISOString(),
            ],

            $result->metadata ?? [],
        );


        /*
        |--------------------------------------------------------------------------
        | Persist successful calculation
        |--------------------------------------------------------------------------
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
     * This method executes after the main transaction has rolled back.
     *
     * Therefore the error state is intentionally persisted separately.
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
        | Because the calculation transaction rolled back, services that
        | were successfully calculated during that transaction are back
        | to their previous state.
        |
        | We mark all non-COMPLETED services as ERROR.
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
     * Recommended future source:
     *
     *     tariff_versions.currency_code
     *
     * If currency_code does not yet exist on tariff_versions,
     * this safely returns null.
     */
    private function resolveCurrency(
        object $version,
    ): ?string {

        return $version->currency_code ?? null;
    }
}