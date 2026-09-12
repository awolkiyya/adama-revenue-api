<?php

namespace App\Domain\Revenue\Calculations;

use App\Models\AssessmentItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class PenaltyCalculator
{
    /**
     * Calculate the current penalty for an assessment item.
     *
     * The calculation uses the penalty rule attached to the
     * assessment item.
     *
     * Important:
     * - Rules determine HOW the penalty is calculated.
     * - This class determines the ACTUAL penalty amount.
     * - The calculation is performed as of $asOfDate.
     */
    public function calculate(
        AssessmentItem $item,
        CarbonInterface|string|null $asOfDate = null
    ): float {
        $asOfDate = $this->normalizeDate($asOfDate);

        $rule = $item->penaltyRule;

        if (!$rule) {
            return 0.0;
        }

        if (!$rule->is_active) {
            return 0.0;
        }

        /*
         * The rule must be legally effective on the calculation date.
         */
        if ($asOfDate->lt(Carbon::parse($rule->effective_from))) {
            return 0.0;
        }

        if (
            $rule->effective_to !== null &&
            $asOfDate->gt(Carbon::parse($rule->effective_to))
        ) {
            return 0.0;
        }

        /*
         * Determine when penalty starts.
         */
        $commencementDate = $this->determineCommencementDate(
            $item,
            $rule,
            $asOfDate
        );

        /*
         * No penalty before commencement.
         */
        if ($asOfDate->lte($commencementDate)) {
            return 0.0;
        }

        /*
         * Determine the number of completed penalty periods.
         */
        $periods = $this->calculateCompletedPeriods(
            $commencementDate,
            $asOfDate,
            $rule->increment_period
        );

        if ($periods <= 0) {
            return 0.0;
        }

        /*
         * Determine the monetary basis.
         */
        $basis = $this->determineBasis($item, $rule);

        if ($basis <= 0) {
            return 0.0;
        }

        /*
         * Progressive rate.
         *
         * Example:
         *
         * initial = 5%
         * increment = 2%
         *
         * Period 1 = 5%
         * Period 2 = 7%
         * Period 3 = 9%
         *
         * capped at maximum_rate.
         */
        $rate = (
            (float) $rule->initial_rate
            +
            (
                max(0, $periods - 1)
                *
                (float) $rule->increment_rate
            )
        );

        $rate = min(
            $rate,
            (float) $rule->maximum_rate
        );

        return $this->roundMoney(
            $basis * ($rate / 100)
        );
    }

    /**
     * Determine the date from which penalty starts.
     */
    protected function determineCommencementDate(
        AssessmentItem $item,
        object $rule,
        CarbonInterface $asOfDate
    ): CarbonInterface {
        return match ($rule->start_type) {
            'AGREEMENT_DATE' => $this->agreementDate($item),

            'FIXED_FISCAL_MONTH' => $this->fixedFiscalMonthStart(
                $asOfDate
            ),

            default => throw new InvalidArgumentException(
                "Unsupported penalty start type: {$rule->start_type}"
            ),
        };
    }

    /**
     * Agreement-based commencement.
     */
    protected function agreementDate(
        AssessmentItem $item
    ): CarbonInterface {
        if (!$item->agreement_date) {
            throw new InvalidArgumentException(
                'Agreement date is required for AGREEMENT_DATE penalty rules.'
            );
        }

        return Carbon::parse($item->agreement_date)->startOfDay();
    }

    /**
     * Determine the configured fiscal payment-period start.
     *
     * IMPORTANT:
     *
     * This assumes revenue_settings contains:
     *
     * payment_start_month
     * payment_start_day
     *
     * Replace this retrieval with your actual RevenueSetting model.
     */
    protected function fixedFiscalMonthStart(
        CarbonInterface $asOfDate
    ): CarbonInterface {
        $settings = app('App\Models\RevenueSetting');

        $settings = $settings::query()->first();

        if (!$settings) {
            throw new InvalidArgumentException(
                'Revenue settings are not configured.'
            );
        }

        $month = (int) $settings->payment_start_month;
        $day = (int) $settings->payment_start_day;

        $year = $asOfDate->year;

        $start = Carbon::create(
            $year,
            $month,
            $day,
            0,
            0,
            0
        );

        /*
         * If the configured fiscal/payment period starts later
         * in the current calendar year, use the previous year.
         */
        if ($start->gt($asOfDate)) {
            $start->subYear();
        }

        return $start->startOfDay();
    }

    /**
     * Calculate completed penalty periods.
     */
    protected function calculateCompletedPeriods(
        CarbonInterface $start,
        CarbonInterface $end,
        string $period
    ): int {
        return match ($period) {
            'MONTH' => $start->copy()
                ->startOfDay()
                ->diffInMonths(
                    $end->copy()->startOfDay()
                ),

            default => throw new InvalidArgumentException(
                "Unsupported penalty increment period: {$period}"
            ),
        };
    }

    /**
     * Determine the monetary basis.
     */
    protected function determineBasis(
        AssessmentItem $item,
        object $rule
    ): float {
        return match ($rule->calculation_basis) {
            'PRINCIPAL' => (float) $item->principal_amount,

            'OUTSTANDING' => max(
                0,
                (float) $item->principal_amount
                -
                (float) ($item->paid_principal_amount ?? 0)
            ),

            default => throw new InvalidArgumentException(
                "Unsupported penalty calculation basis: {$rule->calculation_basis}"
            ),
        };
    }

    /**
     * Normalize date input.
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
     * Government monetary calculations should normally
     * be rounded to two decimal places.
     */
    protected function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}