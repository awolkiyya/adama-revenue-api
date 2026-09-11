<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RevenueService extends Model
{
    use SoftDeletes, HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'revenue_services';

    /*
    |--------------------------------------------------------------------------
    | UUID
    |--------------------------------------------------------------------------
    */

    public $incrementing = false;

    protected $keyType = 'string';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'revenue_code_id',
        'name',
        'description',
        'service_type',
        'collection_mode',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Revenue Code
     *
     * RevenueCode
     *      |
     *      └── RevenueService
     */
    public function revenueCode(): BelongsTo
    {
        return $this->belongsTo(
            RevenueCode::class,
            'revenue_code_id'
        );
    }

    /**
     * Configured Revenue Service Fields
     *
     * RevenueService
     *      |
     *      └── RevenueServiceField
     *                  |
     *                  └── BaseField
     */
    public function fields(): HasMany
    {
        return $this->hasMany(
            RevenueServiceField::class,
            'service_id'
        )->orderBy('sort_order');
    }

    /**
     * Service Access Rules
     *
     * RevenueService
     *      |
     *      └── ServiceAccessRule
     *                  |
     *                  └── Sector
     */
    public function accessRules(): HasMany
    {
        return $this->hasMany(
            ServiceAccessRule::class,
            'service_id'
        );
    }

    /**
     * Created By
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * Updated By
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    /**
     * Assessments generated from this service
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(
            Assessment::class,
            'revenue_service_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only active revenue services.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            'is_active',
            true
        );
    }

    /**
     * Only inactive revenue services.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where(
            'is_active',
            false
        );
    }

    /**
     * Revenue services accessible to a user.
     *
     * SYSTEM_ADMIN:
     *     Can access all services.
     *
     * Other users:
     *     Can access only services that have an
     *     active access rule for their sector.
     */
    public function scopeAccessibleTo(
        Builder $query,
        User $user
    ): Builder {
        if ($user->hasRole('SYSTEM_ADMIN')) {
            return $query;
        }

        if (! $user->sector_id) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'accessRules',
            function (Builder $accessQuery) use ($user) {
                $accessQuery
                    ->where(
                        'sector_id',
                        $user->sector_id
                    )
                    ->where(
                        'is_active',
                        true
                    );
            }
        );
    }
}