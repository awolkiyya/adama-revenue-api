<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaseAmendment extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'lease_amendments';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        // Amendment identification
        'amendment_number',
        'amendment_type',
        'status',

        // Assessment references
        'previous_assessment_id',
        'new_assessment_id',

        // Amendment explanation
        'reason',
        'other_amendment_description',

        // Submission
        'submitted_at',

        // Decision and approval
        'decided_by',
        'decision_notes',
        'decided_at',
        'approved_at',
        'rejected_at',

        // Application tracking
        'applied_by',
        'applied_at',

        // Additional structured data
        'metadata',

        // Audit users
        'created_by',
        'updated_by',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'metadata' => 'array',

            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Assessment Relationships
    |--------------------------------------------------------------------------
    */

    public function previousAssessment(): BelongsTo
    {
        return $this->belongsTo(
            Assessment::class,
            'previous_assessment_id'
        );
    }

    public function newAssessment(): BelongsTo
    {
        return $this->belongsTo(
            Assessment::class,
            'new_assessment_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Amendment Changes
    |--------------------------------------------------------------------------
    |
    | Taxpayer changes, land-area changes, and other field-level
    | modifications are recorded in lease_amendment_changes.
    |
    */

    public function changes(): HasMany
    {
        return $this->hasMany(
            LeaseAmendmentChange::class,
            'lease_amendment_id'
        )->orderBy('change_order');
    }

    /*
    |--------------------------------------------------------------------------
    | User Relationships
    |--------------------------------------------------------------------------
    */

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decided_by'
        );
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'applied_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Supporting Files
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

