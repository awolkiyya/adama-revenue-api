<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TariffVersion extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The primary key type.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'year',
        'version',
        'name',
        'description',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'year'           => 'integer',
        'version'        => 'integer',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Tariff rules belonging to this tariff version.
     */
    public function tariffRules(): HasMany
    {
        return $this->hasMany(TariffRule::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Active tariff versions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Tariff versions for a specific year.
     */
    public function scopeYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Currently effective tariff versions.
     */
    public function scopeEffective($query)
    {
        $today = now()->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Example:
     * 2026 - Version 2
     */
    public function getDisplayNameAttribute(): string
    {
        return "{$this->year} - Version {$this->version}";
    }

    /**
     * Whether this tariff version is currently effective.
     */
    public function getIsCurrentlyEffectiveAttribute(): bool
    {
        $today = now()->startOfDay();

        if ($this->effective_from->gt($today)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->lt($today)) {
            return false;
        }

        return true;
    }
}