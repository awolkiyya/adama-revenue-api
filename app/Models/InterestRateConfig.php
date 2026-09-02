<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterestRateConfig extends Model
{
    use HasFactory, HasUuids;

    /**
     * ============================================================
     * TABLE
     * ============================================================
     */
    protected $table = 'interest_rate_configs';

    /**
     * ============================================================
     * PRIMARY KEY
     * ============================================================
     */
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * ============================================================
     * MASS ASSIGNMENT
     * ============================================================
     */
    protected $fillable = [
        'name',
        'rate',
        'rate_type',
        'effective_from',
        'effective_to',
        'is_active',
        'source',
        'description',
        'created_by',
        'updated_by',
    ];

    /**
     * ============================================================
     * CASTS
     * ============================================================
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * ============================================================
     * CREATED BY
     * ============================================================
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ============================================================
     * UPDATED BY
     * ============================================================
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * ============================================================
     * SCOPE: ACTIVE
     * ============================================================
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * ============================================================
     * SCOPE: EFFECTIVE
     * ============================================================
     *
     * Returns rates that are effective on a given date.
     */
    public function scopeEffectiveOn($query, $date)
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });
    }

    /**
     * ============================================================
     * SCOPE: CURRENT
     * ============================================================
     */
    public function scopeCurrent($query, $date = null)
    {
        $date ??= now()->toDateString();

        return $query
            ->active()
            ->effectiveOn($date);
    }

    /**
     * ============================================================
     * HELPERS
     * ============================================================
     */

    /**
     * Determine whether the rate is effective on a given date.
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

    /**
     * Convert annual rate to monthly rate.
     *
     * Example:
     * 0.24725 / 12 = 0.0206041667
     */
    public function monthlyRate(): float
    {
        return (float) $this->rate / 12;
    }
}