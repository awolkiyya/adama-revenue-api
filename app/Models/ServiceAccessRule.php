<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceAccessRule extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'service_access_rules';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'service_id',
        'sector_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * Attribute casts.
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
     * Revenue service.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(
            RevenueService::class,
            'service_id'
        );
    }

    /**
     * Sector.
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(
            Sector::class,
            'sector_id'
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
     * Scope to active access rules.
     */
    public function scopeActive($query)
    {
        return $query->where(
            'is_active',
            true
        );
    }

    /**
     * Scope to inactive access rules.
     */
    public function scopeInactive($query)
    {
        return $query->where(
            'is_active',
            false
        );
    }
}