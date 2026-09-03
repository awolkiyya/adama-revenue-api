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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only administratively active rules.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Only inactive rules.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
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

    /*
    |--------------------------------------------------------------------------
    | Interest Rate Helpers
    |--------------------------------------------------------------------------
    |
    | The stored rate is ALWAYS an annual percentage.
    |
    | Example:
    |
    | rate = 24.7250
    |
    | Means:
    |
    | 24.725% per year
    |
    */

    /**
     * Get the annual rate as a decimal.
     *
     * Example:
     * 24.7250 -> 0.24725
     */
    public function getAnnualRateDecimal(): string
    {
        return bcdiv((string) $this->rate, '100', 10);
    }

    /**
     * Get the derived monthly rate as a percentage.
     *
     * Example:
     * 24.7250 / 12 = 2.0604167
     */
    public function getMonthlyRate(): string
    {
        return bcdiv((string) $this->rate, '12', 10);
    }

    /**
     * Get the derived monthly rate as a decimal.
     *
     * Example:
     * 2.0604167% -> 0.020604167
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
     * Get the derived daily rate as a percentage.
     *
     * Uses 365 days per year.
     */
    public function getDailyRate(): string
    {
        return bcdiv((string) $this->rate, '365', 10);
    }

    /**
     * Get the derived daily rate as a decimal.
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
    | Calculation Basis Helpers
    |--------------------------------------------------------------------------
    */

    public function usesPrincipal(): bool
    {
        return $this->calculation_basis === 'PRINCIPAL';
    }

    public function usesOutstanding(): bool
    {
        return $this->calculation_basis === 'OUTSTANDING';
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Period
    |--------------------------------------------------------------------------
    */

    public function isEffectiveOn($date): bool
    {
        if ($this->effective_from->gt($date)) {
            return false;
        }

        if (
            $this->effective_to !== null &&
            $this->effective_to->lt($date)
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

    public function isCurrentlyEffective(): bool
    {
        return $this->is_active
            && $this->isEffectiveOn(now()->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Status Label
    |--------------------------------------------------------------------------
    */

    public function getStatusLabelAttribute(): string
    {
        return $this->is_active
            ? 'ACTIVE'
            : 'INACTIVE';
    }

    /*
    |--------------------------------------------------------------------------
    | Calculation Basis Label
    |--------------------------------------------------------------------------
    */

    public function getCalculationBasisLabelAttribute(): string
    {
        return match ($this->calculation_basis) {
            'PRINCIPAL' => 'Principal',
            'OUTSTANDING' => 'Outstanding Balance',
            default => $this->calculation_basis,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Label
    |--------------------------------------------------------------------------
    */

    public function getRateLabelAttribute(): string
    {
        return number_format(
            (float) $this->rate,
            4
        ) . '% per year';
    }
}