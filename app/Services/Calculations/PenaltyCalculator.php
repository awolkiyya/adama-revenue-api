<?php

namespace App\Services\Calculations;

use App\Models\AssessmentService;
use App\Models\RevenueSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PenaltyCalculator
{
    /**
     * ================================================================
     * CALCULATE PENALTY
     * ================================================================
     *
     * Calculates the penalty applicable to an assessment service
     * as of the supplied calculation date.
     *
     * The calculation is deterministic:
     *
     *     same assessment service
     *     + same rule
     *     + same as-of date
     *     = same penalty
     */
    public function calculate(
        AssessmentService $item,
        CarbonInterface|string|null $asOfDate = null
    ): float {
        $asOfDate = $this->normalizeDate($asOfDate);

        Log::info('Penalty calculation started.', [
            'assessment_service_id' => $item->getKey(),
            'assessment_service_class' => $item::class,
            'as_of_date' => $asOfDate->toDateString(),
            'due_date' => $item->due_date,
            'agreement_date' => $item->agreement_date ?? null,
            'computed_amount' => $item->computed_amount ?? null,
            'principal_amount' => $item->principal_amount ?? null,
            'paid_principal_amount' => $item->paid_principal_amount ?? null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. RESOLVE PENALTY RULE
        |--------------------------------------------------------------------------
        */

        $rule = $item->penaltyRule;

        if (! $rule) {
            Log::info('Penalty calculation skipped: no penalty rule.', [
                'assessment_service_id' => $item->getKey(),
            ]);

            return 0.0;
        }

        Log::info('Penalty rule resolved.', [
            'assessment_service_id' => $item->getKey(),
            'penalty_rule_id' => $rule->getKey(),
            'is_active' => $rule->is_active,
            'start_type' => $rule->start_type,
            'calculation_basis' => $rule->calculation_basis,
            'increment_period' => $rule->increment_period,
            'initial_rate' => $rule->initial_rate,
            'increment_rate' => $rule->increment_rate,
            'maximum_rate' => $rule->maximum_rate,
            'effective_from' => $rule->effective_from,
            'effective_to' => $rule->effective_to,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2. ACTIVE CHECK
        |--------------------------------------------------------------------------
        */

        if (! $rule->is_active) {
            Log::info('Penalty calculation skipped: penalty rule inactive.', [
                'assessment_service_id' => $item->getKey(),
                'penalty_rule_id' => $rule->getKey(),
            ]);

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 3. EFFECTIVE DATE CHECK
        |--------------------------------------------------------------------------
        */

        if ($rule->effective_from !== null) {
            $effectiveFrom = Carbon::parse(
                $rule->effective_from
            )->startOfDay();

            if ($asOfDate->lt($effectiveFrom)) {
                Log::info(
                    'Penalty calculation skipped: calculation date is before rule effective date.',
                    [
                        'assessment_service_id' => $item->getKey(),
                        'penalty_rule_id' => $rule->getKey(),
                        'effective_from' => $effectiveFrom->toDateString(),
                        'as_of_date' => $asOfDate->toDateString(),
                    ]
                );

                return 0.0;
            }
        }

        if ($rule->effective_to !== null) {
            $effectiveTo = Carbon::parse(
                $rule->effective_to
            )->startOfDay();

            if ($asOfDate->gt($effectiveTo)) {
                Log::info(
                    'Penalty calculation skipped: calculation date is after rule effective date.',
                    [
                        'assessment_service_id' => $item->getKey(),
                        'penalty_rule_id' => $rule->getKey(),
                        'effective_to' => $effectiveTo->toDateString(),
                        'as_of_date' => $asOfDate->toDateString(),
                    ]
                );

                return 0.0;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. DETERMINE COMMENCEMENT DATE
        |--------------------------------------------------------------------------
        */

        $commencementDate = $this->determineCommencementDate(
            item: $item,
            rule: $rule,
            asOfDate: $asOfDate,
        );

        Log::info('Penalty commencement date resolved.', [
            'assessment_service_id' => $item->getKey(),
            'penalty_rule_id' => $rule->getKey(),
            'start_type' => $rule->start_type,
            'commencement_date' => $commencementDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. PENALTY COMMENCEMENT CHECK
        |--------------------------------------------------------------------------
        */

        if ($asOfDate->lte($commencementDate)) {
            Log::info(
                'Penalty calculation skipped: penalty has not commenced.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'commencement_date' => $commencementDate->toDateString(),
                    'as_of_date' => $asOfDate->toDateString(),
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 6. CALCULATE COMPLETED PENALTY PERIODS
        |--------------------------------------------------------------------------
        */

        $periods = $this->calculateCompletedPeriods(
            start: $commencementDate,
            end: $asOfDate,
            period: $rule->increment_period,
        );

        Log::info('Penalty periods calculated.', [
            'assessment_service_id' => $item->getKey(),
            'penalty_rule_id' => $rule->getKey(),
            'increment_period' => $rule->increment_period,
            'completed_periods' => $periods,
        ]);

        if ($periods <= 0) {
            Log::info(
                'Penalty calculation skipped: no completed penalty periods.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'periods' => $periods,
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 7. DETERMINE CALCULATION BASIS
        |--------------------------------------------------------------------------
        */

        $basis = $this->determineBasis(
            item: $item,
            rule: $rule,
        );

        Log::info('Penalty calculation basis resolved.', [
            'assessment_service_id' => $item->getKey(),
            'penalty_rule_id' => $rule->getKey(),
            'calculation_basis' => $rule->calculation_basis,
            'basis_amount' => $basis,
        ]);

        if ($basis <= 0) {
            Log::info(
                'Penalty calculation skipped: calculation basis is zero.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'basis' => $basis,
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 8. RESOLVE RATE
        |--------------------------------------------------------------------------
        */

        $initialRate = max(
            0.0,
            (float) $rule->initial_rate
        );

        $incrementRate = max(
            0.0,
            (float) $rule->increment_rate
        );

        $maximumRate = max(
            0.0,
            (float) $rule->maximum_rate
        );

        $rate = $initialRate
            + (
                max(0, $periods - 1)
                * $incrementRate
            );

        /*
        |--------------------------------------------------------------------------
        | Apply maximum rate
        |--------------------------------------------------------------------------
        */

        if ($maximumRate > 0) {
            $rate = min(
                $rate,
                $maximumRate
            );
        }

        Log::info('Penalty rate resolved.', [
            'assessment_service_id' => $item->getKey(),
            'penalty_rule_id' => $rule->getKey(),
            'initial_rate' => $initialRate,
            'increment_rate' => $incrementRate,
            'maximum_rate' => $maximumRate,
            'completed_periods' => $periods,
            'final_rate' => $rate,
        ]);

        if ($rate <= 0) {
            Log::info(
                'Penalty calculation skipped: final penalty rate is zero.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'rate' => $rate,
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 9. CALCULATE PENALTY
        |--------------------------------------------------------------------------
        */

        $penalty = $basis * ($rate / 100);

        $penalty = $this->roundMoney(
            $penalty
        );

        Log::info('Penalty calculation completed.', [
            'assessment_service_id' => $item->getKey(),
            'penalty_rule_id' => $rule->getKey(),
            'basis' => $basis,
            'rate' => $rate,
            'penalty_amount' => $penalty,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        return $penalty;
    }

    /**
     * ================================================================
     * DETERMINE COMMENCEMENT DATE
     * ================================================================
     */
    protected function determineCommencementDate(
        AssessmentService $item,
        object $rule,
        CarbonInterface $asOfDate
    ): CarbonInterface {
        return match ($rule->start_type) {

            /*
             * Penalty starts from the agreement signing date.
             */
            'AGREEMENT_DATE' =>
                $this->agreementDate($item),

            /*
             * Penalty starts from the configured municipal
             * fiscal/payment cycle date.
             */
            'FIXED_FISCAL_MONTH' =>
                $this->fixedFiscalMonthStart($asOfDate),

            /*
             * Penalty starts from the assessment service payment
             * due date.
             */
            'FIXED_PAYMENT_DATE' =>
                $this->fixedPaymentDate($item),

            default =>
                throw new InvalidArgumentException(
                    "Unsupported penalty start type: {$rule->start_type}"
                ),
        };
    }

    /**
     * ================================================================
     * AGREEMENT DATE
     * ================================================================
     */
    protected function agreementDate(
        AssessmentService $item
    ): CarbonInterface {
        if (! $item->agreement_date) {
            Log::error(
                'Penalty calculation failed: agreement date is required.',
                [
                    'assessment_service_id' => $item->getKey(),
                ]
            );

            throw new InvalidArgumentException(
                'Agreement date is required for AGREEMENT_DATE penalty rules.'
            );
        }

        return Carbon::parse(
            $item->agreement_date
        )->startOfDay();
    }

    /**
     * ================================================================
     * FIXED PAYMENT DATE
     * ================================================================
     *
     * FIXED_PAYMENT_DATE uses the AssessmentService due_date as
     * the penalty commencement date.
     *
     * This means:
     *
     *     payment due date
     *             ↓
     *     overdue period begins
     *             ↓
     *     completed penalty periods
     *             ↓
     *     penalty rate
     */
    protected function fixedPaymentDate(
        AssessmentService $item
    ): CarbonInterface {
        if (! $item->due_date) {
            Log::error(
                'Penalty calculation failed: payment due date is required.',
                [
                    'assessment_service_id' => $item->getKey(),
                ]
            );

            throw new InvalidArgumentException(
                'Due date is required for FIXED_PAYMENT_DATE penalty rules.'
            );
        }

        return Carbon::parse(
            $item->due_date
        )->startOfDay();
    }

    /**
     * ================================================================
     * FIXED FISCAL MONTH START
     * ================================================================
     */
    protected function fixedFiscalMonthStart(
        CarbonInterface $asOfDate
    ): CarbonInterface {
        $settings = RevenueSetting::query()->first();

        if (! $settings) {
            Log::error(
                'Penalty calculation failed: revenue settings are not configured.'
            );

            throw new InvalidArgumentException(
                'Revenue settings are not configured.'
            );
        }

        $month = (int) $settings->payment_start_month;
        $day = (int) $settings->payment_start_day;

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(
                'Revenue setting payment_start_month must be between 1 and 12.'
            );
        }

        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException(
                'Revenue setting payment_start_day must be between 1 and 31.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent invalid dates such as February 31.
        |--------------------------------------------------------------------------
        */

        $year = $asOfDate->year;

        $start = Carbon::create(
            $year,
            $month,
            1,
            0,
            0,
            0
        );

        $lastDayOfMonth = $start->daysInMonth;

        $day = min(
            $day,
            $lastDayOfMonth
        );

        $start->day($day);

        if ($start->gt($asOfDate)) {
            $start->subYear();
        }

        return $start->startOfDay();
    }

    /**
     * ================================================================
     * CALCULATE COMPLETED PERIODS
     * ================================================================
     */
    protected function calculateCompletedPeriods(
        CarbonInterface $start,
        CarbonInterface $end,
        string $period
    ): int {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($end->lte($start)) {
            return 0;
        }

        return match ($period) {

            'MONTH' =>
                $start->diffInMonths($end),

            default =>
                throw new InvalidArgumentException(
                    "Unsupported penalty increment period: {$period}"
                ),
        };
    }

    /**
     * ================================================================
     * DETERMINE PENALTY BASIS
     * ================================================================
     *
     * AssessmentService does not use AssessmentItem here.
     *
     * The authoritative assessment amount in the current domain model
     * is computed_amount.
     */
    protected function determineBasis(
        AssessmentService $item,
        object $rule
    ): float {
        $computedAmount = max(
            0.0,
            (float) ($item->computed_amount ?? 0)
        );

        $paidPrincipalAmount = max(
            0.0,
            (float) ($item->paid_principal_amount ?? 0)
        );

        return match ($rule->calculation_basis) {

            'PRINCIPAL' =>
                $computedAmount,

            'OUTSTANDING' =>
                max(
                    0.0,
                    $computedAmount - $paidPrincipalAmount
                ),

            default =>
                throw new InvalidArgumentException(
                    "Unsupported penalty calculation basis: {$rule->calculation_basis}"
                ),
        };
    }

    /**
     * ================================================================
     * NORMALIZE DATE
     * ================================================================
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
     * ================================================================
     * ROUND MONEY
     * ================================================================
     */
    protected function roundMoney(
        float $amount
    ): float {
        return round(
            $amount,
            2
        );
    }
}
