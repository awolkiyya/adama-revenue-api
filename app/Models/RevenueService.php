<?php

namespace App\Models;

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

    public function scopeActive($query)
    {
        return $query->where(
            'is_active',
            true
        );
    }

    public function scopeInactive($query)
    {
        return $query->where(
            'is_active',
            false
        );
    }
}