<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterestRule extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    |
    | Canonical application values used by:
    |
    | - Validation requests
    | - Services
    | - Resources
    | - Controllers
    | - Frontend API contracts
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Rate Periods
    |--------------------------------------------------------------------------
    */

    public const RATE_PERIOD_YEAR = 'YEAR';

    public const RATE_PERIOD_MONTH = 'MONTH';

    public const RATE_PERIOD_DAY = 'DAY';

    /*
    |--------------------------------------------------------------------------
    | Calculation Methods
    |--------------------------------------------------------------------------
    */

    public const METHOD_SIMPLE = 'SIMPLE';

    public const METHOD_COMPOUND = 'COMPOUND';

    /*
    |--------------------------------------------------------------------------
    | Calculation Bases
    |--------------------------------------------------------------------------
    */

    public const BASIS_PRINCIPAL = 'PRINCIPAL';

    public const BASIS_OUTSTANDING = 'OUTSTANDING';

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'interest_rules';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'rate',
        'rate_period',
        'calculation_method',
        'calculation_basis',
        'effective_from',
        'effective_to',
        'is_active',
        'legal_reference',
        'description',
        'created_by',
        'updated_by',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    |
    | Keep financial decimals as strings to avoid PHP floating-point
    | precision issues.
    |
    */

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',

            'effective_from' => 'date',
            'effective_to' => 'date',

            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * User who created this interest rule.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who last updated this interest rule.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only administratively active rules.
     *
     * NOTE:
     * Active does not necessarily mean currently effective.
     * An active rule may be scheduled for a future effective date.
     */
    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'is_active',
            true
        );
    }

    /**
     * Only administratively inactive rules.
     */
    public function scopeInactive(
        Builder $query
    ): Builder {
        return $query->where(
            'is_active',
            false
        );
    }

    /**
     * Rules effective on a specific date.
     *
     * Effective period is inclusive:
     *
     * effective_from <= date <= effective_to
     *
     * A NULL effective_to means the rule has no end date.
     */
    public function scopeEffectiveOn(
        Builder $query,
        $date
    ): Builder {
        return $query
            ->whereDate(
                'effective_from',
                '<=',
                $date
            )
            ->where(function (
                Builder $query
            ) use ($date) {
                $query
                    ->whereNull(
                        'effective_to'
                    )
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $date
                    );
            });
    }

    /**
     * Active rules that are effective on a specific date.
     */
    public function scopeActiveEffectiveOn(
        Builder $query,
        $date
    ): Builder {
        return $query
            ->active()
            ->effectiveOn($date);
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Period
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the rate is annual.
     */
    public function isAnnual(): bool
    {
        return $this->rate_period === self::RATE_PERIOD_YEAR;
    }

    /**
     * Determine whether the rate is monthly.
     */
    public function isMonthly(): bool
    {
        return $this->rate_period === self::RATE_PERIOD_MONTH;
    }

    /**
     * Determine whether the rate is daily.
     */
    public function isDaily(): bool
    {
        return $this->rate_period === self::RATE_PERIOD_DAY;
    }

    /**
     * Get the rate period label.
     */
    public function getRatePeriodLabelAttribute(): string
    {
        return match ($this->rate_period) {
            self::RATE_PERIOD_YEAR => 'Annual',
            self::RATE_PERIOD_MONTH => 'Monthly',
            self::RATE_PERIOD_DAY => 'Daily',
            default => (string) $this->rate_period,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Calculation Method
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether simple interest is used.
     */
    public function usesSimpleInterest(): bool
    {
        return $this->calculation_method === self::METHOD_SIMPLE;
    }

    /**
     * Determine whether compound interest is used.
     */
    public function usesCompoundInterest(): bool
    {
        return $this->calculation_method === self::METHOD_COMPOUND;
    }

    /**
     * Get the calculation method label.
     */
    public function getCalculationMethodLabelAttribute(): string
    {
        return match ($this->calculation_method) {
            self::METHOD_SIMPLE => 'Simple Interest',
            self::METHOD_COMPOUND => 'Compound Interest',
            default => (string) $this->calculation_method,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Interest Rate Helpers
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | The stored rate is a percentage whose meaning depends on
    | rate_period.
    |
    | Examples:
    |
    | 24.7250 + YEAR
    | = 24.725% per year
    |
    | 2.0000 + MONTH
    | = 2% per month
    |
    | 0.0500 + DAY
    | = 0.05% per day
    |
    | These helpers normalize configured rates.
    |
    | They DO NOT calculate actual monetary interest.
    |
    */

    /**
     * Get the configured rate as a decimal.
     *
     * Example:
     *
     * 24.7250% -> 0.247250
     */
    public function getRateDecimal(): string
    {
        return bcdiv(
            (string) $this->rate,
            '100',
            10
        );
    }

    /**
     * Get the annual rate as a percentage.
     *
     * YEAR:
     * 24.7250 -> 24.7250
     *
     * MONTH:
     * 2.0000 -> 24.0000
     *
     * DAY:
     * 0.0500 -> 18.2500
     *
     * Daily conversion uses 365 days.
     */
    public function getAnnualRate(): string
    {
        return match ($this->rate_period) {
            self::RATE_PERIOD_YEAR => bcdiv(
                (string) $this->rate,
                '1',
                10
            ),

            self::RATE_PERIOD_MONTH => bcmul(
                (string) $this->rate,
                '12',
                10
            ),

            self::RATE_PERIOD_DAY => bcmul(
                (string) $this->rate,
                '365',
                10
            ),

            default => '0',
        };
    }

    /**
     * Get the annual rate as a decimal.
     *
     * Example:
     *
     * 24.725% -> 0.24725
     */
    public function getAnnualRateDecimal(): string
    {
        return bcdiv(
            $this->getAnnualRate(),
            '100',
            10
        );
    }

    /**
     * Get the monthly rate as a percentage.
     *
     * YEAR:
     * annual rate / 12
     *
     * MONTH:
     * configured monthly rate
     *
     * DAY:
     * daily rate * 30
     *
     * These are normalized helper values only.
     *
     * The actual financial calculation engine should use
     * the appropriate calendar-period logic.
     */
    public function getMonthlyRate(): string
    {
        return match ($this->rate_period) {
            self::RATE_PERIOD_YEAR => bcdiv(
                (string) $this->rate,
                '12',
                10
            ),

            self::RATE_PERIOD_MONTH => bcdiv(
                (string) $this->rate,
                '1',
                10
            ),

            self::RATE_PERIOD_DAY => bcmul(
                (string) $this->rate,
                '30',
                10
            ),

            default => '0',
        };
    }

    /**
     * Get the monthly rate as a decimal.
     */
    public function getMonthlyRateDecimal(): string
    {
        return bcdiv(
            $this->getMonthlyRate(),
            '100',
            10
        );
    }

    /**
     * Get the daily rate as a percentage.
     *
     * YEAR:
     * annual / 365
     *
     * MONTH:
     * monthly / 30
     *
     * DAY:
     * configured daily rate
     *
     * These are normalized helper values only.
     */
    public function getDailyRate(): string
    {
        return match ($this->rate_period) {
            self::RATE_PERIOD_YEAR => bcdiv(
                (string) $this->rate,
                '365',
                10
            ),

            self::RATE_PERIOD_MONTH => bcdiv(
                (string) $this->rate,
                '30',
                10
            ),

            self::RATE_PERIOD_DAY => bcdiv(
                (string) $this->rate,
                '1',
                10
            ),

            default => '0',
        };
    }

    /**
     * Get the daily rate as a decimal.
     */
    public function getDailyRateDecimal(): string
    {
        return bcdiv(
            $this->getDailyRate(),
            '100',
            10
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Calculation Basis
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether interest is calculated on principal.
     */
    public function usesPrincipal(): bool
    {
        return $this->calculation_basis === self::BASIS_PRINCIPAL;
    }

    /**
     * Determine whether interest is calculated on outstanding balance.
     */
    public function usesOutstanding(): bool
    {
        return $this->calculation_basis === self::BASIS_OUTSTANDING;
    }

    /**
     * Get calculation basis label.
     */
    public function getCalculationBasisLabelAttribute(): string
    {
        return match ($this->calculation_basis) {
            self::BASIS_PRINCIPAL => 'Principal',
            self::BASIS_OUTSTANDING => 'Outstanding Balance',
            default => (string) $this->calculation_basis,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Period
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this rule is effective on a date.
     *
     * The effective period is inclusive.
     */
    public function isEffectiveOn(
        $date
    ): bool {
        if ($this->effective_from === null) {
            return false;
        }

        /*
         * Normalize the comparison date to a date-only value.
         *
         * This prevents timezone/time-of-day differences from affecting
         * an effective-date comparison.
         */
        $date = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : (string) $date;

        if (
            $this->effective_from->format('Y-m-d') > $date
        ) {
            return false;
        }

        if (
            $this->effective_to !== null &&
            $this->effective_to->format('Y-m-d') < $date
        ) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Current Effectiveness
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the rule is both administratively active
     * and currently effective.
     */
    public function isCurrentlyEffective(): bool
    {
        return $this->is_active
            && $this->isEffectiveOn(
                now()->toDateString()
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    /**
     * Get the administrative status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->is_active
            ? 'ACTIVE'
            : 'INACTIVE';
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Label
    |--------------------------------------------------------------------------
    */

    /**
     * Get a human-readable rate label.
     *
     * Examples:
     *
     * 24.7250% per year
     * 2.0000% per month
     * 0.0500% per day
     */
    public function getRateLabelAttribute(): string
    {
        $periodLabel = match ($this->rate_period) {
            self::RATE_PERIOD_YEAR => 'per year',
            self::RATE_PERIOD_MONTH => 'per month',
            self::RATE_PERIOD_DAY => 'per day',
            default => '',
        };

        return number_format(
            (float) $this->rate,
            4,
            '.',
            ''
        ) . '% ' . $periodLabel;
    }
}
