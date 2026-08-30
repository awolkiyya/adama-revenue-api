<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TariffRule extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tariff_version_id',
        'service_id',
        'code',
        'name',
        'description',
        'calculation_type',
        'base_field_id',
        'measurement_unit_id',
        'priority',
        'execution_order',
        'min_value',
        'max_value',
        'amount',
        'percentage',
        'minimum_amount',
        'maximum_amount',
        'formula',
        'conditions',
        'rounding_rule',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'min_value' => 'decimal:4',
        'max_value' => 'decimal:4',
        'amount' => 'decimal:4',
        'percentage' => 'decimal:4',
        'minimum_amount' => 'decimal:4',
        'maximum_amount' => 'decimal:4',

        'conditions' => 'array',

        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function tariffVersion(): BelongsTo
    {
        return $this->belongsTo(
            TariffVersion::class,
            'tariff_version_id'
        );
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(
            RevenueService::class,
            'service_id'
        );
    }

    /**
     * Primary/base field used by non-formula calculation types.
     */
    public function baseField(): BelongsTo
    {
        return $this->belongsTo(
            BaseField::class,
            'base_field_id'
        );
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementUnit::class,
            'measurement_unit_id'
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

    /**
     * Variables used by a FORMULA calculation.
     *
     * Example:
     *
     * LAND_AREA     -> BASE_FIELD
     * BUILDING_AREA -> BASE_FIELD
     * RATE          -> CONSTANT
     */
    public function formulaVariables(): HasMany
    {
        return $this->hasMany(
            TariffFormulaVariable::class,
            'tariff_rule_id'
        )->orderBy('sort_order');
    }

    /**
     * Other tariff rules belonging to the same tariff version.
     *
     * Used for:
     * - priority conflict detection
     * - execution order conflict detection
     * - range overlap validation
     */
    public function relatedRules(): HasMany
    {
        return $this->hasMany(
            TariffRule::class,
            'tariff_version_id',
            'tariff_version_id'
        )
        ->where('id', '!=', $this->id);
    }
}