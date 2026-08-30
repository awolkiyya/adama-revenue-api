<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssessmentService extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'assessment_services';

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
        'assessment_id',
        'service_id',
        'service_order',
        'status',

        /*
        |--------------------------------------------------------------------------
        | Decision Provider Output
        |--------------------------------------------------------------------------
        */

        'computed_amount',
        'currency_code',
        'calculation_metadata',
        'calculation_error',
        'calculated_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'service_order' => 'integer',

            'computed_amount' => 'decimal:4',

            'calculation_metadata' => 'array',

            'calculated_at' => 'datetime',

            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UUID
    |--------------------------------------------------------------------------
    */

    public function uniqueIds(): array
    {
        return [
            'id',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Parent Assessment
    |--------------------------------------------------------------------------
    */

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(
            Assessment::class,
            'assessment_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Revenue Service
    |--------------------------------------------------------------------------
    |
    | assessment_services.service_id
    |              ↓
    | revenue_services.id
    |
    */

    public function service(): BelongsTo
    {
        return $this->belongsTo(
            RevenueService::class,
            'service_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Captured Field Values
    |--------------------------------------------------------------------------
    |
    | assessment_services.id
    |              ↓
    | assessment_service_values.assessment_service_id
    |
    */

    public function values(): HasMany
    {
        return $this->hasMany(
            AssessmentServiceValue::class,
            'assessment_service_id'
        )->orderBy('sort_order');
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

    /*
    |--------------------------------------------------------------------------
    | Status Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCaptured(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'CAPTURED'
        );
    }

    public function scopeProcessing(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'PROCESSING'
        );
    }

    public function scopeCompleted(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'COMPLETED'
        );
    }

    public function scopeError(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'ERROR'
        );
    }

    public function scopeCancelled(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'CANCELLED'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public function isCaptured(): bool
    {
        return $this->status === 'CAPTURED';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'PROCESSING';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    public function hasError(): bool
    {
        return $this->status === 'ERROR';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'CANCELLED';
    }
}