<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseField extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * Input sources supported by a base field.
     */
    public const INPUT_SOURCE_MANUAL = 'MANUAL';

    public const INPUT_SOURCE_AI = 'AI';

    public const INPUT_SOURCE_MANUAL_OR_AI = 'MANUAL_OR_AI';

    public const INPUT_SOURCES = [
        self::INPUT_SOURCE_MANUAL,
        self::INPUT_SOURCE_AI,
        self::INPUT_SOURCE_MANUAL_OR_AI,
    ];

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
        'input_source',
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
    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementUnit::class,
            'measurement_unit_id'
        );
    }

    /**
     * Options configured for this base field.
     */
    public function options(): HasMany
    {
        return $this->hasMany(
            BaseFieldOption::class,
            'base_field_id'
        )->orderBy('sort_order');
    }

    /*
    |--------------------------------------------------------------------------
    | Input Source Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this field supports manual input.
     */
    public function supportsManualInput(): bool
    {
        return in_array($this->input_source, [
            self::INPUT_SOURCE_MANUAL,
            self::INPUT_SOURCE_MANUAL_OR_AI,
        ], true);
    }

    /**
     * Determine whether this field supports AI input.
     */
    public function supportsAiInput(): bool
    {
        return in_array($this->input_source, [
            self::INPUT_SOURCE_AI,
            self::INPUT_SOURCE_MANUAL_OR_AI,
        ], true);
    }
}
