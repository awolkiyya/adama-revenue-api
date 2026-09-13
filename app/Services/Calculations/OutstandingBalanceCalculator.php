<?php

namespace App\Services\Calculations;

use App\Models\Assessment;
use App\Models\AssessmentService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class OutstandingBalanceCalculator
{
    public function __construct(
        protected PenaltyCalculator $penaltyCalculator,
        protected InterestCalculator $interestCalculator,
    ) {
    }

    /**
     * Calculate the current financial balance for an assessment.
     *
     * Important:
     *
     * - assessment_services.computed_amount is the original principal.
     * - assessment_services.due_date is the legal payment deadline.
     * - penalty_rule_id and interest_rule_id identify the rules
     *   applicable to the obligation.
     * - Penalty and interest are calculated dynamically as of $asOfDate.
     * - Penalty and interest must NOT be added back into computed_amount.
     * - Payments must be resolved from the actual payment/allocation
     *   records when that schema is available.
     *
     * The returned values represent the current financial position,
     * not a mutation of the assessment principal.
     */
    public function calculate(
        Assessment $assessment,
        CarbonInterface|string|null $asOfDate = null
    ): array {
        $asOfDate = $this->normalizeDate($asOfDate);

        $principal = 0.0;
        $penalty = 0.0;
        $interest = 0.0;
        $paid = 0.0;

        /*
         * Assessment services are the financial obligation units.
         *
         * We intentionally do not use the old AssessmentItem model.
         */
        $services = $assessment->relationLoaded('services')
            ? $assessment->services
            : $assessment->services()
                ->with([
                    'penaltyRule',
                    'interestRule',
                ])
                ->get();

        $serviceResults = [];

        foreach ($services as $assessmentService) {
            /*
             * Only financially calculated services should contribute
             * to the outstanding balance.
             */
            if (
                $assessmentService->status !== null
                && $assessmentService->status !== 'COMPLETED'
            ) {
                continue;
            }

            $servicePrincipal = max(
                0.0,
                (float) ($assessmentService->computed_amount ?? 0)
            );

            /*
             * Penalty is calculated dynamically from:
             *
             * - persisted due_date
             * - persisted penalty rule
             * - current calculation date
             */
            $servicePenalty = $this->penaltyCalculator->calculate(
                $assessmentService,
                $asOfDate
            );

            /*
             * Interest is calculated dynamically from:
             *
             * - persisted due_date
             * - persisted interest rule
             * - current calculation date
             */
            $serviceInterest = $this->interestCalculator->calculate(
                $assessmentService,
                $asOfDate
            );

            /*
             * Resolve payments separately.
             *
             * This method intentionally does not assume a payment
             * column exists on assessment_services.
             */
            $servicePaid = $this->resolvePaidAmount(
                $assessmentService,
                $asOfDate
            );

            /*
             * Total obligation before payments.
             */
            $serviceGrossTotal =
                $servicePrincipal
                + $servicePenalty
                + $serviceInterest;

            /*
             * Current unpaid amount.
             */
            $serviceOutstanding = max(
                0.0,
                $serviceGrossTotal - $servicePaid
            );

            $principal += $servicePrincipal;
            $penalty += $servicePenalty;
            $interest += $serviceInterest;
            $paid += $servicePaid;

            $serviceResults[] = [
                'id' => $assessmentService->id,

                'service_id' => $assessmentService->service_id,

                'computed_amount' => $this->roundMoney(
                    $servicePrincipal
                ),

                'due_date' => $assessmentService->due_date
                    ? Carbon::parse(
                        $assessmentService->due_date
                    )->toDateString()
                    : null,

                'penalty_rule_id' =>
                    $assessmentService->penalty_rule_id,

                'interest_rule_id' =>
                    $assessmentService->interest_rule_id,

                'penalty' => $this->roundMoney(
                    $servicePenalty
                ),

                'interest' => $this->roundMoney(
                    $serviceInterest
                ),

                'gross_total' => $this->roundMoney(
                    $serviceGrossTotal
                ),

                'paid' => $this->roundMoney(
                    $servicePaid
                ),

                'outstanding' => $this->roundMoney(
                    $serviceOutstanding
                ),
            ];
        }

        /*
         * Assessment-level totals.
         */
        $grossTotal =
            $principal
            + $penalty
            + $interest;

        $outstanding = max(
            0.0,
            $grossTotal - $paid
        );

        return [
            'as_of_date' => $asOfDate->toDateString(),

            'principal' => $this->roundMoney(
                $principal
            ),

            'penalty' => $this->roundMoney(
                $penalty
            ),

            'interest' => $this->roundMoney(
                $interest
            ),

            'gross_total' => $this->roundMoney(
                $grossTotal
            ),

            'paid' => $this->roundMoney(
                $paid
            ),

            'outstanding_principal' => $this->roundMoney(
                max(
                    0.0,
                    $principal - $paid
                )
            ),

            'outstanding' => $this->roundMoney(
                $outstanding
            ),

            'is_paid' => $outstanding <= 0,

            'is_overdue' => $this->isOverdue(
                $assessment,
                $asOfDate
            ),

            'services' => $serviceResults,
        ];
    }

    /**
     * Determine whether the assessment is fully paid.
     *
     * This uses the current financial balance rather than simply
     * checking whether a due date has passed.
     */
    public function isPaid(
        Assessment $assessment,
        CarbonInterface|string|null $asOfDate = null
    ): bool {
        $balance = $this->calculate(
            $assessment,
            $asOfDate
        );

        return $balance['outstanding'] <= 0;
    }

    /**
     * Determine whether the assessment contains an unpaid
     * obligation whose legal due date has passed.
     *
     * A paid assessment is not considered overdue merely because
     * its historical due date is in the past.
     */
    public function isOverdue(
        Assessment $assessment,
        CarbonInterface|string|null $asOfDate = null
    ): bool {
        $asOfDate = $this->normalizeDate($asOfDate);

        $services = $assessment->relationLoaded('services')
            ? $assessment->services
            : $assessment->services()->get();

        foreach ($services as $assessmentService) {
            /*
             * No due date means the service cannot be classified
             * as overdue.
             */
            if (!$assessmentService->due_date) {
                continue;
            }

            /*
             * No principal means there is no financial obligation.
             */
            if (
                (float) (
                    $assessmentService->computed_amount ?? 0
                ) <= 0
            ) {
                continue;
            }

            /*
             * Determine whether anything remains unpaid.
             *
             * We deliberately use the actual payment resolver
             * rather than a non-existent paid_amount column.
             */
            $paid = $this->resolvePaidAmount(
                $assessmentService,
                $asOfDate
            );

            $principal = max(
                0.0,
                (float) (
                    $assessmentService->computed_amount ?? 0
                )
            );

            /*
             * If the entire principal has already been paid,
             * the service is not overdue.
             *
             * This check is intentionally based on principal
             * because penalty/interest may themselves remain
             * calculable after the principal has been paid.
             */
            if ($paid >= $principal) {
                continue;
            }

            $dueDate = Carbon::parse(
                $assessmentService->due_date
            )->startOfDay();

            if ($asOfDate->gt($dueDate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the amount already paid against an assessment service.
     *
     * IMPORTANT:
     *
     * The payment/allocation schema has not been supplied here.
     *
     * Therefore this method intentionally does NOT assume:
     *
     *     $assessmentService->paid_amount
     *
     * or any invented payment relationship.
     *
     * Connect this method to the actual payment allocation service
     * once the payment schema is established.
     *
     * The returned value must represent payments allocated to the
     * assessment service as of $asOfDate.
     */
    protected function resolvePaidAmount(
        AssessmentService $assessmentService,
        CarbonInterface $asOfDate
    ): float {
        /*
         * No payment source is assumed until the actual payment
         * allocation model/relation is defined.
         *
         * Returning zero here is correct only while payment
         * allocation has not yet been integrated.
         */
        return 0.0;
    }

    /**
     * Normalize calculation date.
     */
    protected function normalizeDate(
        CarbonInterface|string|null $date
    ): CarbonInterface {
        if ($date instanceof CarbonInterface) {
            return $date->copy()->startOfDay();
        }

        return $date
            ? Carbon::parse($date)->startOfDay()
            : now()->startOfDay();
    }

    /**
     * Round monetary values to two decimal places.
     */
    protected function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}
