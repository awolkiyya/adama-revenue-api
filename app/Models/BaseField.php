<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseField extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'base_fields';

    /**
     * Primary key type.
     */
    protected $keyType = 'string';

    /**
     * Indicates if IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'measurement_unit_id',
        'data_type',
        'is_active',
        'sort_order',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The measurement unit associated with this base field.
     */
    public function measurementUnit()
    {
        return $this->belongsTo(
            MeasurementUnit::class,
            'measurement_unit_id'
        );
    }

    public function options(): HasMany
    {
        return $this->hasMany(
            BaseFieldOption::class,
            'base_field_id'
        )->orderBy('sort_order');
    }
}