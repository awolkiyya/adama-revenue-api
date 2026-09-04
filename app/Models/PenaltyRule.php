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
    use HasFactory, HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */

    public const START_TYPE_FIXED_FISCAL_MONTH = 'FIXED_FISCAL_MONTH';

    public const START_TYPE_AGREEMENT_DATE = 'AGREEMENT_DATE';

    public const CALCULATION_BASIS_PRINCIPAL = 'PRINCIPAL';

    public const CALCULATION_BASIS_OUTSTANDING = 'OUTSTANDING';

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
        'revenue_service_id',

        'name',

        'initial_rate',
        'increment_rate',
        'maximum_rate',

        'start_type',
        'start_fiscal_month',

        'increment_period',
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
            'initial_rate' => 'decimal:4',
            'increment_rate' => 'decimal:4',
            'maximum_rate' => 'decimal:4',

            'start_type' => 'string',
            'start_fiscal_month' => 'integer',

            'increment_period' => 'string',
            'calculation_basis' => 'string',

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

    public function revenueService(): BelongsTo
    {
        return $this->belongsTo(
            RevenueService::class,
            'revenue_service_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('revenue_service_id');
    }

    public function scopeServiceSpecific(Builder $query): Builder
    {
        return $query->whereNotNull('revenue_service_id');
    }

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

    public function scopeActiveEffectiveOn(
        Builder $query,
        CarbonInterface|string $date
    ): Builder {
        return $query
            ->active()
            ->effectiveOn($date);
    }

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
    | Rule Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the highest-priority active penalty rule
     * for a revenue service and date.
     *
     * Priority:
     *
     * 1. Service-specific rule
     * 2. Global/default rule
     * 3. No rule
     */
    public static function resolveForService(
        string $revenueServiceId,
        CarbonInterface|string $date
    ): ?self {
        return static::query()
            ->active()
            ->effectiveOn($date)
            ->where(function (Builder $query) use ($revenueServiceId) {
                $query
                    ->where(
                        'revenue_service_id',
                        $revenueServiceId
                    )
                    ->orWhereNull('revenue_service_id');
            })
            ->orderByRaw(
                'CASE
                    WHEN revenue_service_id IS NULL THEN 1
                    ELSE 0
                 END'
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Scope Helpers
    |--------------------------------------------------------------------------
    */

    public function isGlobal(): bool
    {
        return $this->revenue_service_id === null;
    }

    public function isServiceSpecific(): bool
    {
        return $this->revenue_service_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Start Type Helpers
    |--------------------------------------------------------------------------
    */

    public function startsFromFiscalMonth(): bool
    {
        return $this->start_type === self::START_TYPE_FIXED_FISCAL_MONTH;
    }

    public function startsFromAgreementDate(): bool
    {
        return $this->start_type === self::START_TYPE_AGREEMENT_DATE;
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Period
    |--------------------------------------------------------------------------
    */

    public function isEffectiveOn(
        CarbonInterface|string $date
    ): bool {
        $date = $date instanceof CarbonInterface
            ? $date
            : Carbon::parse($date);

        if ($date->lt($this->effective_from)) {
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

    /*
    |--------------------------------------------------------------------------
    | Rate Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the configured penalty percentage
     * for a monthly penalty period.
     *
     * Example:
     *
     * Period 1  = 5%
     * Period 2  = 7%
     * Period 3  = 9%
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

        $initialRate = $this->getInitialRate();
        $incrementRate = $this->getIncrementRate();
        $maximumRate = $this->getMaximumRate();

        $rate = bcadd(
            $initialRate,
            bcmul(
                (string) ($period - 1),
                $incrementRate,
                4
            ),
            4
        );

        if (bccomp($rate, $maximumRate, 4) > 0) {
            $rate = $maximumRate;
        }

        return $rate;
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Helpers
    |--------------------------------------------------------------------------
    */

    public function getInitialRate(): string
    {
        return $this->initial_rate ?? '5.0000';
    }

    public function getIncrementRate(): string
    {
        return $this->increment_rate ?? '2.0000';
    }

    public function getMaximumRate(): string
    {
        return $this->maximum_rate ?? '25.0000';
    }

    /*
    |--------------------------------------------------------------------------
    | Display Helpers
    |--------------------------------------------------------------------------
    */

    public function getStartTypeLabelAttribute(): string
    {
        return match ($this->start_type) {
            self::START_TYPE_FIXED_FISCAL_MONTH =>
                'Ethiopian Fiscal Month',

            self::START_TYPE_AGREEMENT_DATE =>
                'Agreement Date',

            default =>
                $this->start_type,
        };
    }

    public function getScopeLabelAttribute(): string
    {
        return $this->isGlobal()
            ? 'Global'
            : 'Service Specific';
    }

    public function getProgressionLabelAttribute(): string
    {
        return sprintf(
            '%s%% initial + %s%% per month, maximum %s%%',
            $this->getInitialRate(),
            $this->getIncrementRate(),
            $this->getMaximumRate()
        );
    }
}