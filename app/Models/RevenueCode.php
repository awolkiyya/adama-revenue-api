<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RevenueCode extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /**
     * Revenue category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(
            RevenueCategory::class,
            'category_id'
        );
    }

    /**
     * Office responsible for this revenue code.
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(
            Office::class,
            'office_id'
        );
    }

    /**
     * Payment schedule configuration.
     *
     * A revenue code can have zero or one payment schedule rule.
     */
    public function paymentScheduleRule(): HasOne
    {
        return $this->hasOne(
            RevenueCodePaymentScheduleRule::class,
            'revenue_code_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BUSINESS HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this revenue code uses payment scheduling.
     */
    public function usesPaymentSchedule(): bool
    {
        return (bool) $this->paymentScheduleRule?->is_enabled;
    }
}