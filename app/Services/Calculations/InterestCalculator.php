<?php

namespace App\Services\Calculations;

use App\Models\AssessmentService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class InterestCalculator
{
    /**
     * ================================================================
     * CALCULATE INTEREST
     * ================================================================
     */
    public function calculate(
        AssessmentService $item,
        CarbonInterface|string|null $asOfDate = null
    ): float {

        $asOfDate = $this->normalizeDate($asOfDate);

        Log::info('Interest calculation started.', [
            'assessment_service_id' => $item->getKey(),
            'assessment_service_class' => $item::class,
            'as_of_date' => $asOfDate->toDateString(),
            'due_date' => $item->due_date,
            'computed_amount' => $item->computed_amount ?? null,
            'principal_amount' => $item->principal_amount ?? null,
            'paid_principal_amount' => $item->paid_principal_amount ?? null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. RESOLVE INTEREST RULE
        |--------------------------------------------------------------------------
        */

        $rule = $item->interestRule;

        if (! $rule) {

            Log::info(
                'Interest calculation skipped: no interest rule.',
                [
                    'assessment_service_id' => $item->getKey(),
                ]
            );

            return 0.0;
        }

        Log::info('Interest rule resolved.', [
            'assessment_service_id' => $item->getKey(),
            'interest_rule_id' => $rule->getKey(),
            'is_active' => $rule->is_active,
            'calculation_basis' => $rule->calculation_basis,
            'calculation_method' => $rule->calculation_method,
            'rate' => $rule->rate,
            'rate_period' => $rule->rate_period,
            'effective_from' => $rule->effective_from,
            'effective_to' => $rule->effective_to,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2. ACTIVE CHECK
        |--------------------------------------------------------------------------
        */

        if (! $rule->is_active) {

            Log::info(
                'Interest calculation skipped: interest rule inactive.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'interest_rule_id' => $rule->getKey(),
                ]
            );

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
                    'Interest calculation skipped: before effective date.',
                    [
                        'assessment_service_id' => $item->getKey(),
                        'interest_rule_id' => $rule->getKey(),
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
                    'Interest calculation skipped: after effective date.',
                    [
                        'assessment_service_id' => $item->getKey(),
                        'interest_rule_id' => $rule->getKey(),
                        'effective_to' => $effectiveTo->toDateString(),
                        'as_of_date' => $asOfDate->toDateString(),
                    ]
                );

                return 0.0;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. DUE DATE
        |--------------------------------------------------------------------------
        */

        if (! $item->due_date) {

            Log::warning(
                'Interest calculation skipped: assessment service has no due date.',
                [
                    'assessment_service_id' => $item->getKey(),
                ]
            );

            return 0.0;
        }

        $dueDate = Carbon::parse(
            $item->due_date
        )->startOfDay();

        Log::info('Interest due date resolved.', [
            'assessment_service_id' => $item->getKey(),
            'due_date' => $dueDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. OVERDUE CHECK
        |--------------------------------------------------------------------------
        */

        if ($asOfDate->lte($dueDate)) {

            Log::info(
                'Interest calculation skipped: assessment service is not overdue.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'due_date' => $dueDate->toDateString(),
                    'as_of_date' => $asOfDate->toDateString(),
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 6. DETERMINE BASIS
        |--------------------------------------------------------------------------
        */

        $basis = $this->determineBasis(
            item: $item,
            rule: $rule,
        );

        Log::info('Interest calculation basis resolved.', [
            'assessment_service_id' => $item->getKey(),
            'interest_rule_id' => $rule->getKey(),
            'calculation_basis' => $rule->calculation_basis,
            'basis_amount' => $basis,
        ]);

        if ($basis <= 0) {

            Log::info(
                'Interest calculation skipped: calculation basis is zero.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'basis' => $basis,
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 7. RATE
        |--------------------------------------------------------------------------
        */

        $rate = max(
            0.0,
            (float) $rule->rate
        );

        if ($rate <= 0) {

            Log::info(
                'Interest calculation skipped: interest rate is zero.',
                [
                    'assessment_service_id' => $item->getKey(),
                    'rate' => $rate,
                ]
            );

            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | 8. CALCULATE INTEREST
        |--------------------------------------------------------------------------
        */

        $interest = match ($rule->calculation_method) {

            'SIMPLE' => $this->simpleInterest(
                principal: $basis,
                rate: $rate,
                period: $rule->rate_period,
                dueDate: $dueDate,
                asOfDate: $asOfDate,
            ),

            'COMPOUND' => $this->compoundInterest(
                principal: $basis,
                rate: $rate,
                period: $rule->rate_period,
                dueDate: $dueDate,
                asOfDate: $asOfDate,
            ),

            default => throw new InvalidArgumentException(
                "Unsupported interest calculation method: {$rule->calculation_method}"
            ),
        };

        Log::info('Interest calculation completed.', [
            'assessment_service_id' => $item->getKey(),
            'interest_rule_id' => $rule->getKey(),
            'calculation_method' => $rule->calculation_method,
            'calculation_basis' => $rule->calculation_basis,
            'basis' => $basis,
            'rate' => $rate,
            'rate_period' => $rule->rate_period,
            'interest_amount' => $interest,
            'due_date' => $dueDate->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        return $interest;
    }

    /**
     * ================================================================
     * SIMPLE INTEREST
     * ================================================================
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
            asOfDate: $asOfDate,
        );

        $interest = $principal
            * ($rate / 100)
            * $time;

        return $this->roundMoney(
            $interest
        );
    }

    /**
     * ================================================================
     * COMPOUND INTEREST
     * ================================================================
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
            asOfDate: $asOfDate,
        );

        if ($periods <= 0) {
            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | Convert annual rate to monthly rate.
        |--------------------------------------------------------------------------
        |
        | For MONTH:
        |     supplied rate is monthly.
        |
        | For DAY:
        |     supplied rate is daily.
        |
        | For YEAR:
        |     supplied rate is annual and is compounded monthly.
        |
        */

        $periodRate = match ($period) {

            'YEAR' =>
                ($rate / 100) / 12,

            'MONTH' =>
                $rate / 100,

            'DAY' =>
                $rate / 100,

            default =>
                throw new InvalidArgumentException(
                    "Unsupported interest rate period: {$period}"
                ),
        };

        /*
        |--------------------------------------------------------------------------
        | YEAR is represented by completed months.
        |--------------------------------------------------------------------------
        */

        $amount = $principal
            * pow(
                1 + $periodRate,
                $periods
            );

        $interest = $amount - $principal;

        return $this->roundMoney(
            $interest
        );
    }

    /**
     * ================================================================
     * SIMPLE INTEREST PERIOD FRACTION
     * ================================================================
     */
    protected function periodFraction(
        string $period,
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): float {

        return match ($period) {

            'YEAR' =>
                $this->completedMonths(
                    dueDate: $dueDate,
                    asOfDate: $asOfDate
                ) / 12,

            'MONTH' =>
                $this->completedMonths(
                    dueDate: $dueDate,
                    asOfDate: $asOfDate
                ),

            'DAY' =>
                $dueDate->diffInDays($asOfDate),

            default =>
                throw new InvalidArgumentException(
                    "Unsupported interest rate period: {$period}"
                ),
        };
    }

    /**
     * ================================================================
     * COMPLETED INTEREST PERIODS
     * ================================================================
     */
    protected function completedPeriods(
        string $period,
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): int {

        return match ($period) {

            'YEAR',
            'MONTH' =>
                $this->completedMonths(
                    dueDate: $dueDate,
                    asOfDate: $asOfDate
                ),

            'DAY' =>
                $dueDate->diffInDays($asOfDate),

            default =>
                throw new InvalidArgumentException(
                    "Unsupported interest rate period: {$period}"
                ),
        };
    }

    /**
     * ================================================================
     * COMPLETED MONTHS
     * ================================================================
     */
    protected function completedMonths(
        CarbonInterface $dueDate,
        CarbonInterface $asOfDate
    ): int {

        if ($asOfDate->lte($dueDate)) {
            return 0;
        }

        return $dueDate->diffInMonths(
            $asOfDate
        );
    }

    /**
     * ================================================================
     * DETERMINE INTEREST BASIS
     * ================================================================
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
                    "Unsupported interest calculation basis: {$rule->calculation_basis}"
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