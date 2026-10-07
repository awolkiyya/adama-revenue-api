<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaseAmendment extends Model
{
    protected $table = 'lease_amendments';

    protected $fillable = [
        'amendment_number',
        'previous_assessment_id',
        'new_assessment_id',
        'amendment_type',
        'status',
        'reason',
        'created_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

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

    public function changes(): HasMany
    {
        return $this->hasMany(
            LeaseAmendmentChange::class,
            'lease_amendment_id'
        )->orderBy('change_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by'
        );
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'rejected_by'
        );
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'applied_by'
        );
    }

    public function files()
    {
        /*
         * Replace with your project's actual file relationship.
         */
        return $this->morphMany(
            PrivateFile::class,
            'fileable'
        );
    }
}