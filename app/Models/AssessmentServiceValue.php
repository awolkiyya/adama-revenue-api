<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssessmentServiceValue extends Model
{
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'assessment_service_values';

    /*
    |--------------------------------------------------------------------------
    | Primary Key
    |--------------------------------------------------------------------------
    */

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'assessment_service_id',
        'revenue_service_field_id',
        'field_code',
        'field_label',
        'data_type',
        'input_type',
        'value',
        'display_value',
        'measurement_unit_id',
        'sort_order',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            /*
             * Field values can be:
             *
             * - string
             * - integer
             * - decimal
             * - boolean
             * - date string
             * - SELECT value
             * - checkbox array
             * - file path / file metadata
             */
            'value' => 'json',

            /*
             * Preserve field ordering as an integer.
             */
            'sort_order' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Assessment Service
    |--------------------------------------------------------------------------
    */

    public function assessmentService(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentService::class,
            'assessment_service_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Revenue Service Field
    |--------------------------------------------------------------------------
    */

    public function revenueServiceField(): BelongsTo
    {
        return $this->belongsTo(
            RevenueServiceField::class,
            'revenue_service_field_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Measurement Unit
    |--------------------------------------------------------------------------
    */

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementUnit::class,
            'measurement_unit_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    public function files(): MorphMany
    {
        return $this->morphMany(
            File::class,
            'fileable'
        );
    }
}