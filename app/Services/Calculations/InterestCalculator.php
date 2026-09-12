<?php

namespace App\Domain\Revenue\Calculations;

use App\Models\AssessmentItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class InterestCalculator
{
    /**
     * Calculate accrued interest for an assessment item.
     *
     * Interest starts after the item's due date.
     *
     * YEAR:
     *     Annual rate is accrued based on completed months.
     *
     *     Example:
     *         Principal = 100,000
     *         Rate      = 24.725% / YEAR
     *         Elapsed   = 3 months
     *
     *         Interest =
     *             100,000 × 24.725% × (3 / 12)
     *             = 6,181.25
     *
     * MONTH:
     *     Monthly rate is accrued based on completed months.
     *
     * DAY:
     *     Daily rate is accrued based on elapsed days.
     */
    public function calculate(
        AssessmentItem $item,
        CarbonInterface|string|null $asOfDate = null
    ): float {
        $asOfDate = $this->normalizeDate($asOfDate);

        $rule = $item->interestRule;

        /*
         * No interest rule means no interest.
         */
        if (!$rule) {
            return 0.0;
        }

        /*
         * Inactive rules must not be applied.
         */
        if (!$rule->is_active) {
            return 0.0;
        }

        /*
         * Check legal effectiveness.
         */
        if (
            $rule->effective_from !== null &&
            $asOfDate->lt(
                Carbon::parse($rule->effective_from)->startOfDay()
            )
        ) {
            return 0.0;
        }

        if (
            $rule->effective_to !== null &&
            $asOfDate->gt(
                Carbon::parse($rule->effective_to)->startOfDay()
            )
        ) {
            return 0.0;
        }

        /*
         * Interest requires a due date.
         */
        if (!$item->due_date) {
            return 0.0;
        }

        $dueDate = Carbon::parse($item->due_date)
            ->startOfDay();

        /*
         * Interest does not accrue on or before the due date.
         */
        if ($asOfDate->lte($dueDate)) {
            return 0.0;
        }

        /*
         * Determine the monetary basis.
         */
        $basis = $this->determineBasis(
            $item,
            $rule
        );

        if ($basis <= 0) {
            return 0.0;
        }

        $rate = (float) $rule->rate;

        if ($rate <= 0) {
            return 0.0;
        }

        /*
         * Calculate interest according to the configured method.
         */
        return match ($rule->calculation_method) {
            'SIMPLE' => $this->simpleInterest(
                principal: $basis,
                rate: $rate,
                period: $rule->rate_period,
                dueDate: $dueDate,
                asOfDate: $asOfDate
            ),

            'COMPOUND' => $this->compoundInterest(
                principal: $basis,
                rate: $rate,
                period: $rule->rate_period,
                dueDate: $dueDate,
                asOfDate: $asOfDate
            ),

            default => throw new InvalidArgumentException(
                "Unsupported interest calculation method: {$rule->calculation_method}"
            ),
        };
    }

    /**
     * Calculate simple interest.
     *
     * Formula:
     *
     *     I = P × r × t
     *
     * For YEAR:
     *
     *     t = completed months / 12
     *
     * For MONTH:
     *
     *     t = completed months
     *
     * For DAY:
     *
     *     t = elapsed days
     */
    protected function simpleInterest(
        float $principal,
        float $rate,
        string $period,
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): float {
        $time = $this->periodFraction(
            period: $period,
            dueDate: $dueDate,
            asOfDate: $asOfDate
        );

        $interest = $principal
            * ($rate / 100)
            * $time;

        return $this->roundMoney($interest);
    }

    /**
     * Calculate compound interest.
     *
     * IMPORTANT:
     *
     * Compound interest requires a periodic compounding convention.
     *
     * This implementation uses:
     *
     * YEAR:
     *     Annual rate converted to a monthly rate:
     *
     *         monthlyRate = annualRate / 12
     *
     *     Compounding occurs for every completed month.
     *
     * MONTH:
     *     Configured rate is the monthly rate.
     *
     * DAY:
     *     Configured rate is the daily rate.
     *
     * Partial periods do not create an additional compound period.
     */
    protected function compoundInterest(
        float $principal,
        float $rate,
        string $period,
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): float {
        $periods = $this->completedPeriods(
            period: $period,
            dueDate: $dueDate,
            asOfDate: $asOfDate
        );

        if ($periods <= 0) {
            return 0.0;
        }

        /*
         * Determine the periodic rate used for compounding.
         */
        $periodRate = match ($period) {
            /*
             * Annual rate compounded monthly.
             *
             * Example:
             *
             * 24.725% / 12
             * = 2.0604166667% per month
             */
            'YEAR' => ($rate / 100) / 12,

            /*
             * Monthly rate.
             */
            'MONTH' => $rate / 100,

            /*
             * Daily rate.
             */
            'DAY' => $rate / 100,

            default => throw new InvalidArgumentException(
                "Unsupported interest rate period: {$period}"
            ),
        };

        $amount = $principal
            * pow(
                1 + $periodRate,
                $periods
            );

        $interest = $amount - $principal;

        return $this->roundMoney($interest);
    }

    /**
     * Convert elapsed time into the configured interest period.
     *
     * YEAR:
     *     Annual rate accrued according to completed months.
     *
     *     Example:
     *
     *     3 months / 12 = 0.25 year
     *
     * MONTH:
     *     Number of completed months.
     *
     * DAY:
     *     Number of elapsed days.
     */
    protected function periodFraction(
        string $period,
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): float {
        return match ($period) {
            /*
             * Annual rate applied according to completed months.
             *
             * 3 months  = 3 / 12
             * 6 months  = 6 / 12
             * 12 months = 12 / 12
             */
            'YEAR' => $this->completedMonths(
                dueDate: $dueDate,
                asOfDate: $asOfDate
            ) / 12,

            /*
             * Monthly rate.
             *
             * 3 completed months = 3 periods.
             */
            'MONTH' => $this->completedMonths(
                dueDate: $dueDate,
                asOfDate: $asOfDate
            ),

            /*
             * Daily rate.
             */
            'DAY' => $dueDate->diffInDays($asOfDate),

            default => throw new InvalidArgumentException(
                "Unsupported interest rate period: {$period}"
            ),
        };
    }

    /**
     * Determine the number of completed interest periods.
     *
     * YEAR:
     *     Compounds monthly.
     *
     * MONTH:
     *     One period per completed month.
     *
     * DAY:
     *     One period per elapsed day.
     */
    protected function completedPeriods(
        string $period,
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): int {
        return match ($period) {
            'YEAR',
            'MONTH' => $this->completedMonths(
                dueDate: $dueDate,
                asOfDate: $asOfDate
            ),

            'DAY' => $dueDate->diffInDays($asOfDate),

            default => throw new InvalidArgumentException(
                "Unsupported interest rate period: {$period}"
            ),
        };
    }

    /**
     * Calculate completed calendar months.
     *
     * Example:
     *
     * Due date:
     *     2026-01-10
     *
     * As of:
     *     2026-04-09
     *
     * Completed months:
     *     2
     *
     * As of:
     *     2026-04-10
     *
     * Completed months:
     *     3
     */
    protected function completedMonths(
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): int {
        if ($asOfDate->lte($dueDate)) {
            return 0;
        }

        /*
         * diffInMonths() gives the number of completed calendar
         * month boundaries between the two dates.
         */
        return $dueDate->diffInMonths($asOfDate);
    }

    /**
     * Determine the monetary basis.
     *
     * PRINCIPAL:
     *     Interest is calculated against the original principal.
     *
     * OUTSTANDING:
     *     Interest is calculated against the unpaid principal.
     */
    protected function determineBasis(
        AssessmentItem $item,
        object $rule
    ): float {
        return match ($rule->calculation_basis) {
            'PRINCIPAL' => max(
                0,
                (float) $item->principal_amount
            ),

            'OUTSTANDING' => max(
                0,
                (float) $item->principal_amount
                -
                (float) ($item->paid_principal_amount ?? 0)
            ),

            default => throw new InvalidArgumentException(
                "Unsupported interest calculation basis: {$rule->calculation_basis}"
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
     * Round monetary values to two decimal places.
     */
    protected function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}