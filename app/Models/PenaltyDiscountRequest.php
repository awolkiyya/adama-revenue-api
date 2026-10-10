<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PenaltyDiscountRequest extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'penalty_discount_requests';

    /*
    |--------------------------------------------------------------------------
    | Workflow Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /*
    |--------------------------------------------------------------------------
    | Administrative Decisions
    |--------------------------------------------------------------------------
    */

    public const DECISION_APPROVED = 'APPROVED';

    public const DECISION_REJECTED = 'REJECTED';

    /*
    |--------------------------------------------------------------------------
    | Supporting Document Collection
    |--------------------------------------------------------------------------
    */

    public const FILE_COLLECTION_SUPPORTING_DOCUMENTS =
        'penalty_discount_supporting_documents';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    |
    | Financial application fields should only be changed through
    | trusted application-service operations. Keep them out of
    | general request payloads even if they are fillable here.
    |
    */

    protected $fillable = [
        /*
        |--------------------------------------------------------------------------
        | Target
        |--------------------------------------------------------------------------
        */

        'invoice_id',
        'citizen_id',

        /*
        |--------------------------------------------------------------------------
        | Request Details
        |--------------------------------------------------------------------------
        */

        'requested_amount',
        'reason',

        /*
        |--------------------------------------------------------------------------
        | Request Lifecycle
        |--------------------------------------------------------------------------
        */

        'status',

        /*
        |--------------------------------------------------------------------------
        | Request Creator
        |--------------------------------------------------------------------------
        */

        'created_by',
        'submitted_at',

        /*
        |--------------------------------------------------------------------------
        | Administrative Decision
        |--------------------------------------------------------------------------
        */

        'decision',
        'approved_amount',
        'decision_reason',
        'decided_by',
        'decided_at',

        /*
        |--------------------------------------------------------------------------
        | Financial Application
        |--------------------------------------------------------------------------
        */

        'applied_to_invoice',
        'applied_at',
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
            | Financial Values
            |--------------------------------------------------------------------------
            */

            'requested_amount' => 'decimal:4',
            'approved_amount' => 'decimal:4',

            /*
            |--------------------------------------------------------------------------
            | Application State
            |--------------------------------------------------------------------------
            */

            'applied_to_invoice' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Invoice for which the penalty discount was requested.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(
            Invoice::class,
            'invoice_id'
        );
    }

    /**
     * Citizen associated with the invoice/request.
     */
    public function citizen(): BelongsTo
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
    }

    /**
     * User who created the penalty discount request.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who made the administrative decision.
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decided_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Supporting Documents
    |--------------------------------------------------------------------------
    */

    /**
     * All files attached to this penalty discount request.
     *
     * Uses the polymorphic relationship provided by the files table:
     *
     * files.fileable_id   = penalty_discount_requests.id
     * files.fileable_type = App\Models\PenaltyDiscountRequest
     *
     * This relationship includes every file collection attached
     * to this request, not only supporting documents.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(
            File::class,
            'fileable'
        );
    }

    /**
     * Supporting documents attached to this request.
     *
     * Only returns files belonging to the dedicated supporting
     * document collection.
     */
    public function supportingFiles(): MorphMany
    {
        return $this->files()
            ->where(
                'collection',
                self::FILE_COLLECTION_SUPPORTING_DOCUMENTS
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the request is still a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Determine whether the request has been submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Determine whether the request has been approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Determine whether the request has been rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Determine whether the request has been applied to its invoice.
     */
    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED
            || $this->applied_to_invoice === true
            || $this->applied_at !== null;
    }

    /**
     * Determine whether the request has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Determine whether the request can be submitted.
     */
    public function canSubmit(): bool
    {
        return $this->isDraft()
            && ! $this->isApplied();
    }

    /**
     * Determine whether the request can be decided.
     */
    public function canDecide(): bool
    {
        return $this->isSubmitted()
            && ! $this->isApplied();
    }

    /**
     * Determine whether an approved request can be applied.
     */
    public function canApply(): bool
    {
        return $this->isApproved()
            && ! $this->isApplied()
            && $this->approved_amount !== null
            && (float) $this->approved_amount > 0;
    }

    /**
     * Determine whether the request can be cancelled.
     */
    public function canCancel(): bool
    {
        return in_array(
            $this->status,
            [
                self::STATUS_DRAFT,
                self::STATUS_SUBMITTED,
                self::STATUS_APPROVED,
            ],
            true
        ) && ! $this->isApplied();
    }
}
