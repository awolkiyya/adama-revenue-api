<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyRule extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */

    /**
     * Penalty commencement is resolved from the global
     * revenue payment-date configuration.
     *
     * The actual payment date configuration is maintained
     * by the revenue settings / fiscal configuration.
     */
    public const START_TYPE_FIXED_PAYMENT_DATE = 'FIXED_PAYMENT_DATE';

    /**
     * Penalty commencement is resolved from the
     * applicable agreement signing date.
     */
    public const START_TYPE_AGREEMENT_DATE = 'AGREEMENT_DATE';

    /**
     * Penalty is calculated against the original principal amount.
     */
    public const CALCULATION_BASIS_PRINCIPAL = 'PRINCIPAL';

    /**
     * Penalty is calculated against the outstanding balance.
     */
    public const CALCULATION_BASIS_OUTSTANDING = 'OUTSTANDING';

    /**
     * Current supported penalty progression period.
     */
    public const INCREMENT_PERIOD_MONTH = 'MONTH';

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'penalty_rules';

    /*
    |--------------------------------------------------------------------------
    | Primary Key
    |--------------------------------------------------------------------------
    */

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',

        'initial_rate',
        'increment_rate',
        'maximum_rate',

        'increment_period',

        'start_type',

        'calculation_basis',

        'effective_from',
        'effective_to',

        'is_active',

        'description',
        'legal_reference',

        'created_by',
        'updated_by',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Financial Rates
            |--------------------------------------------------------------------------
            |
            | Keep these as decimal strings.
            |
            | Do NOT cast these to float because penalty calculations
            | are financial calculations and must preserve decimal precision.
            |
            */

            'initial_rate' => 'decimal:4',
            'increment_rate' => 'decimal:4',
            'maximum_rate' => 'decimal:4',

            /*
            |--------------------------------------------------------------------------
            | Configuration
            |--------------------------------------------------------------------------
            */

            'increment_period' => 'string',
            'start_type' => 'string',
            'calculation_basis' => 'string',

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            */

            'effective_from' => 'date',
            'effective_to' => 'date',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * User who created this penalty rule.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who last updated this penalty rule.
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
     * Only active penalty rules.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Only inactive penalty rules.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Rules effective on the supplied date.
     */
    public function scopeEffectiveOn(
        Builder $query,
        CarbonInterface|string $date
    ): Builder {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            });
    }

    /**
     * Active rules effective on the supplied date.
     */
    public function scopeActiveEffectiveOn(
        Builder $query,
        CarbonInterface|string $date
    ): Builder {
        return $query
            ->active()
            ->effectiveOn($date);
    }

    /**
     * Rules using the global fixed payment date.
     */
    public function scopeFixedPaymentDate(Builder $query): Builder
    {
        return $query->where(
            'start_type',
            self::START_TYPE_FIXED_PAYMENT_DATE
        );
    }

    /**
     * Rules using agreement-date commencement.
     */
    public function scopeAgreementDate(Builder $query): Builder
    {
        return $query->where(
            'start_type',
            self::START_TYPE_AGREEMENT_DATE
        );
    }

    /**
     * Rules calculated against principal.
     */
    public function scopePrincipalBased(Builder $query): Builder
    {
        return $query->where(
            'calculation_basis',
            self::CALCULATION_BASIS_PRINCIPAL
        );
    }

    /**
     * Rules calculated against outstanding balance.
     */
    public function scopeOutstandingBased(Builder $query): Builder
    {
        return $query->where(
            'calculation_basis',
            self::CALCULATION_BASIS_OUTSTANDING
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rule Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the active penalty rule applicable on a date.
     *
     * Penalty rules are global.
     *
     * Therefore, unlike a service-specific tariff rule, this method
     * does not resolve a rule by revenue_service_id.
     */
    public static function resolve(
        CarbonInterface|string $date
    ): ?self {
        return static::query()
            ->active()
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Resolve the currently applicable active penalty rule.
     */
    public static function resolveCurrent(): ?self
    {
        return static::resolve(
            now()->toDateString()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Period
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this rule is effective on a date.
     */
    public function isEffectiveOn(
        CarbonInterface|string $date
    ): bool {
        $date = $date instanceof CarbonInterface
            ? $date
            : Carbon::parse($date);

        if (
            $this->effective_from === null ||
            $date->lt($this->effective_from)
        ) {
            return false;
        }

        if (
            $this->effective_to !== null &&
            $date->gt($this->effective_to)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether this rule is currently active and effective.
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
    | Start Type Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether commencement uses the global
     * fixed payment date configuration.
     *
     * The actual payment-date configuration is maintained
     * outside this model.
     */
    public function startsFromPaymentDate(): bool
    {
        return $this->start_type ===
            self::START_TYPE_FIXED_PAYMENT_DATE;
    }

    /**
     * Determine whether commencement uses an agreement date.
     */
    public function startsFromAgreementDate(): bool
    {
        return $this->start_type ===
            self::START_TYPE_AGREEMENT_DATE;
    }

    /*
    |--------------------------------------------------------------------------
    | Calculation Basis Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether penalty uses the original principal.
     */
    public function usesPrincipal(): bool
    {
        return $this->calculation_basis ===
            self::CALCULATION_BASIS_PRINCIPAL;
    }

    /**
     * Determine whether penalty uses the outstanding balance.
     */
    public function usesOutstanding(): bool
    {
        return $this->calculation_basis ===
            self::CALCULATION_BASIS_OUTSTANDING;
    }

    /*
    |--------------------------------------------------------------------------
    | Increment Period Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether progression occurs monthly.
     */
    public function incrementsMonthly(): bool
    {
        return $this->increment_period ===
            self::INCREMENT_PERIOD_MONTH;
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the configured penalty percentage
     * for a given penalty period.
     *
     * Example:
     *
     * Initial rate  = 5%
     * Increment     = 2%
     * Maximum       = 25%
     *
     * Period 1  = 5%
     * Period 2  = 7%
     * Period 3  = 9%
     * Period 4  = 11%
     * ...
     * Period 10 = 23%
     * Period 11 = 25%
     * Period 12 = 25%
     *
     * IMPORTANT:
     *
     * This method calculates ONLY the percentage rate.
     *
     * It does NOT calculate:
     *
     * - principal
     * - outstanding balance
     * - monetary penalty
     * - invoice amount
     * - payment amount
     *
     * Actual financial calculation belongs in the dedicated
     * penalty calculation service.
     */
    public function rateForPeriod(int $period): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException(
                'Penalty period must be greater than or equal to 1.'
            );
        }

        $initialRate = $this->getInitialRate();
        $incrementRate = $this->getIncrementRate();
        $maximumRate = $this->getMaximumRate();

        /*
        |--------------------------------------------------------------------------
        | initial_rate + ((period - 1) × increment_rate)
        |--------------------------------------------------------------------------
        */

        $rate = bcadd(
            $initialRate,
            bcmul(
                (string) ($period - 1),
                $incrementRate,
                4
            ),
            4
        );

        /*
        |--------------------------------------------------------------------------
        | Apply Maximum Rate
        |--------------------------------------------------------------------------
        */

        if (
            bccomp(
                $rate,
                $maximumRate,
                4
            ) > 0
        ) {
            $rate = $maximumRate;
        }

        return $rate;
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get initial penalty rate.
     */
    public function getInitialRate(): string
    {
        return $this->initial_rate ?? '5.0000';
    }

    /**
     * Get monthly increment rate.
     */
    public function getIncrementRate(): string
    {
        return $this->increment_rate ?? '2.0000';
    }

    /**
     * Get maximum penalty rate.
     */
    public function getMaximumRate(): string
    {
        return $this->maximum_rate ?? '25.0000';
    }

    /*
    |--------------------------------------------------------------------------
    | Display Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Human-readable commencement type.
     */
    public function getStartTypeLabelAttribute(): string
    {
        return match ($this->start_type) {
            self::START_TYPE_FIXED_PAYMENT_DATE =>
                'Fixed Payment Date',

            self::START_TYPE_AGREEMENT_DATE =>
                'Agreement Date',

            default =>
                (string) $this->start_type,
        };
    }

    /**
     * Human-readable calculation basis.
     */
    public function getCalculationBasisLabelAttribute(): string
    {
        return match ($this->calculation_basis) {
            self::CALCULATION_BASIS_PRINCIPAL =>
                'Principal',

            self::CALCULATION_BASIS_OUTSTANDING =>
                'Outstanding Balance',

            default =>
                (string) $this->calculation_basis,
        };
    }

    /**
     * Human-readable increment period.
     */
    public function getIncrementPeriodLabelAttribute(): string
    {
        return match ($this->increment_period) {
            self::INCREMENT_PERIOD_MONTH =>
                'Monthly',

            default =>
                (string) $this->increment_period,
        };
    }

    /**
     * Human-readable progression.
     */
    public function getProgressionLabelAttribute(): string
    {
        return sprintf(
            '%s%% initial + %s%% per month, maximum %s%%',
            $this->getInitialRate(),
            $this->getIncrementRate(),
            $this->getMaximumRate()
        );
    }

    /**
     * Human-readable status.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->is_active
            ? 'Active'
            : 'Inactive';
    }

    /**
     * Human-readable rate summary.
     */
    public function getRateSummaryAttribute(): string
    {
        return sprintf(
            '%s%% → +%s%%/month → max %s%%',
            $this->getInitialRate(),
            $this->getIncrementRate(),
            $this->getMaximumRate()
        );
    }
}