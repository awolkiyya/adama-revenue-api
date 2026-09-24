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

class Assessment extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'assessments';

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
        'assessment_number',

        /*
        |--------------------------------------------------------------------------
        | Assessment Source
        |--------------------------------------------------------------------------
        |
        | NEW
        | EXISTING_LIZZ
        |
        */

        'source_type',

        /*
        |--------------------------------------------------------------------------
        | Taxpayer
        |--------------------------------------------------------------------------
        */

        'citizen_id',

        /*
        |--------------------------------------------------------------------------
        | Administrative Unit
        |--------------------------------------------------------------------------
        */

        'administrative_unit_id',

        /*
        |--------------------------------------------------------------------------
        | Workflow
        |--------------------------------------------------------------------------
        */

        'status',
        'notes',

        /*
        |--------------------------------------------------------------------------
        | Submission
        |--------------------------------------------------------------------------
        */

        'submitted_at',

        /*
        |--------------------------------------------------------------------------
        | Decision
        |--------------------------------------------------------------------------
        */

        'decided_by',
        'decision',
        'decision_notes',
        'decided_at',

        /*
        |--------------------------------------------------------------------------
        | Approval / Rejection
        |--------------------------------------------------------------------------
        */

        'approved_at',
        'rejected_at',

        /*
        |--------------------------------------------------------------------------
        | Audit
        |--------------------------------------------------------------------------
        */

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
            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

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
    | Citizen
    |--------------------------------------------------------------------------
    |
    | assessments.citizen_id
    |          ↓
    | citizens.id
    |
    */

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Taxpayer
    |--------------------------------------------------------------------------
    |
    | Alias for the citizen relationship.
    |
    | The assessment API/service uses the business term "taxpayer",
    | while the database uses "citizen_id".
    |
    | assessments.citizen_id
    |          ↓
    | citizens.id
    |
    */

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Administrative Unit
    |--------------------------------------------------------------------------
    */

    public function administrativeUnit(): BelongsTo
    {
        return $this->belongsTo(
            AdministrativeUnit::class,
            'administrative_unit_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Decision Officer
    |--------------------------------------------------------------------------
    */

    public function decisionOfficer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decided_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Creator
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Updater
    |--------------------------------------------------------------------------
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
    | Assessment Services
    |--------------------------------------------------------------------------
    |
    | assessments.id
    |       ↓
    | assessment_services.assessment_id
    |
    */

    public function services(): HasMany
    {
        return $this->hasMany(
            AssessmentService::class,
            'assessment_id'
        )->orderBy('service_order');
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | Files attached directly to the assessment.
    |
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
    | Source Type Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope assessments created as new assessments.
     */
    public function scopeNewAssessment(
        Builder $query
    ): Builder {
        return $query->where(
            'source_type',
            'NEW'
        );
    }

    /**
     * Scope Existing LIZZ assessments.
     */
    public function scopeExistingLizz(
        Builder $query
    ): Builder {
        return $query->where(
            'source_type',
            'EXISTING_LIZZ'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeDraft(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'DRAFT'
        );
    }

    public function scopePendingApproval(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'PENDING_APPROVAL'
        );
    }

    public function scopeApproved(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'APPROVED'
        );
    }

    public function scopeRejected(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'REJECTED'
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
    | Source Type Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this is a new assessment.
     */
    public function isNewAssessment(): bool
    {
        return $this->source_type === 'NEW';
    }

    /**
     * Determine whether this is an Existing LIZZ assessment.
     */
    public function isExistingLizz(): bool
    {
        return $this->source_type === 'EXISTING_LIZZ';
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow Helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'PENDING_APPROVAL';
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'CANCELLED';
    }

    public function hasDecision(): bool
    {
        return $this->decision !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Decision Helpers
    |--------------------------------------------------------------------------
    */

    public function wasApproved(): bool
    {
        return $this->decision === 'APPROVED';
    }

    public function wasRejected(): bool
    {
        return $this->decision === 'REJECTED';
    }
}
