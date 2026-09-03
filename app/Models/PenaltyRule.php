<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyRule extends Model
{
    use HasFactory, HasUuids;

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
        'revenue_service_id',

        'name',

        /*
         * Progressive penalty configuration.
         */
        'initial_rate',
        'increment_rate',
        'maximum_rate',

        /*
         * Penalty commencement.
         */
        'start_type',
        'start_fiscal_month',

        /*
         * How the penalty percentage is applied.
         */
        'increment_period',
        'calculation_basis',

        /*
         * Rule validity.
         */
        'effective_from',
        'effective_to',

        'is_active',

        /*
         * Documentation.
         */
        'description',
        'legal_reference',

        /*
         * Audit.
         */
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
            |------------------------------------------------------------------
            | Financial / percentage values
            |------------------------------------------------------------------
            |
            | Decimal casts are returned as strings by Laravel.
            | This avoids silently introducing floating-point values into
            | financial configuration.
            |
            */
            'initial_rate'   => 'decimal:4',
            'increment_rate' => 'decimal:4',
            'maximum_rate'   => 'decimal:4',

            /*
            |------------------------------------------------------------------
            | Integer
            |------------------------------------------------------------------
            */
            'start_fiscal_month' => 'integer',

            /*
            |------------------------------------------------------------------
            | Dates
            |------------------------------------------------------------------
            */
            'effective_from' => 'date',
            'effective_to'   => 'date',

            /*
            |------------------------------------------------------------------
            | Boolean
            |------------------------------------------------------------------
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
     * Revenue service this rule belongs to.
     *
     * NULL revenue_service_id means this is the global/default rule.
     */
    public function revenueService(): BelongsTo
    {
        return $this->belongsTo(
            RevenueService::class,
            'revenue_service_id'
        );
    }

    /**
     * User who created the rule.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who last updated the rule.
     */
    public function updater(): BelongsTo
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
     * Only active rules.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Only global/default rules.
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('revenue_service_id');
    }

    /**
     * Only service-specific rules.
     */
    public function scopeServiceSpecific(Builder $query): Builder
    {
        return $query->whereNotNull('revenue_service_id');
    }

    /**
     * Rules effective on a specific date.
     */
    public function scopeEffectiveOn(
        Builder $query,
        $date
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
     * Active rules effective on a specific date.
     */
    public function scopeActiveEffectiveOn(
        Builder $query,
        $date
    ): Builder {
        return $query
            ->active()
            ->effectiveOn($date);
    }

    /**
     * Rules for a specific revenue service.
     */
    public function scopeForService(
        Builder $query,
        string $revenueServiceId
    ): Builder {
        return $query->where(
            'revenue_service_id',
            $revenueServiceId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scope Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this is the global/default rule.
     */
    public function isGlobal(): bool
    {
        return $this->revenue_service_id === null;
    }

    /**
     * Determine whether this is a service-specific rule.
     */
    public function isServiceSpecific(): bool
    {
        return $this->revenue_service_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Start Type Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Penalty starts from a configured Ethiopian fiscal month.
     */
    public function startsFromFiscalMonth(): bool
    {
        return $this->start_type === 'FIXED_FISCAL_MONTH';
    }

    /**
     * Penalty starts from the agreement date.
     */
    public function startsFromAgreementDate(): bool
    {
        return $this->start_type === 'AGREEMENT_DATE';
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Period
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this rule is effective on a given date.
     */
    public function isEffectiveOn($date): bool
    {
        if ($date < $this->effective_from) {
            return false;
        }

        if (
            $this->effective_to !== null &&
            $date > $this->effective_to
        ) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the configured penalty rate for a late-payment period.
     *
     * Example:
     *
     * Period 1 = 5%
     * Period 2 = 7%
     * Period 3 = 9%
     * ...
     * Period 11 = 25%
     * Period 12+ = 25%
     *
     * This method calculates ONLY the percentage rate.
     * It does not calculate the monetary penalty.
     */
    public function rateForPeriod(int $period): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException(
                'Penalty period must be greater than or equal to 1.'
            );
        }

        $initialRate = $this->initial_rate ?? '5.0000';
        $incrementRate = $this->increment_rate ?? '2.0000';
        $maximumRate = $this->maximum_rate ?? '25.0000';

        /*
         * rate = initial + ((period - 1) × increment)
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
         * Cap at maximum rate.
         */
        if (bccomp($rate, $maximumRate, 4) > 0) {
            $rate = $maximumRate;
        }

        return $rate;
    }

    /*
    |--------------------------------------------------------------------------
    | Default Business Rule Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Initial penalty rate.
     */
    public function getInitialRate(): string
    {
        return $this->initial_rate ?? '5.0000';
    }

    /**
     * Increment per late-payment period.
     */
    public function getIncrementRate(): string
    {
        return $this->increment_rate ?? '2.0000';
    }

    /**
     * Maximum penalty rate.
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
     * Human-readable start type.
     */
    public function getStartTypeLabelAttribute(): string
    {
        return match ($this->start_type) {
            'FIXED_FISCAL_MONTH' => 'Ethiopian Fiscal Month',
            'AGREEMENT_DATE'     => 'Agreement Date',
            default              => $this->start_type,
        };
    }

    /**
     * Human-readable scope.
     */
    public function getScopeLabelAttribute(): string
    {
        return $this->isGlobal()
            ? 'Global'
            : 'Service Specific';
    }

    /**
     * Human-readable penalty progression.
     */
    public function getProgressionLabelAttribute(): string
    {
        return sprintf(
            '%s%% initial + %s%% per %s, maximum %s%%',
            $this->getInitialRate(),
            $this->getIncrementRate(),
            strtolower($this->increment_period ?? 'MONTH'),
            $this->getMaximumRate()
        );
    }
}