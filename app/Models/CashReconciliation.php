<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashReconciliation extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Reconciliation Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';

    public const STATUS_RECONCILED = 'RECONCILED';

    public const STATUS_DISCREPANCY = 'DISCREPANCY';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment Protection
    |--------------------------------------------------------------------------
    |
    | Financial totals, reviewer information, timestamps, and status changes
    | should be controlled by the backend service.
    |
    | These fields remain fillable for compatibility with trusted service
    | operations. Never pass unvalidated request data directly into create()
    | or update() without explicitly controlling the permitted fields.
    |
    */

    protected $fillable = [
        'collector_id',
        'period_start',
        'period_end',
        'expected_amount',
        'actual_amount',
        'difference_amount',
        'collector_notes',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'status',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',

            'expected_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',

            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Revenue collector responsible for this reconciliation.
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    /**
     * Finance officer who reviewed this reconciliation.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Computed Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether actual cash has been recorded.
     */
    public function hasActualAmount(): bool
    {
        return $this->actual_amount !== null;
    }

    /**
     * Determine whether the reconciliation has a cash discrepancy.
     *
     * A positive difference means an overage.
     * A negative difference means a shortage.
     * A zero difference means the amounts balance.
     *
     * A discrepancy cannot be determined until an actual amount and
     * calculated difference have both been recorded.
     */
    public function hasDiscrepancy(): bool
    {
        if (
            $this->actual_amount === null
            || $this->difference_amount === null
        ) {
            return false;
        }

        return (float) $this->difference_amount !== 0.0;
    }

    /**
     * Determine whether the reconciliation is balanced.
     */
    public function isBalanced(): bool
    {
        if (
            $this->actual_amount === null
            || $this->difference_amount === null
        ) {
            return false;
        }

        return (float) $this->difference_amount === 0.0;
    }

    /**
     * Determine whether the reconciliation has a cash shortage.
     */
    public function hasShortage(): bool
    {
        if (
            $this->actual_amount === null
            || $this->difference_amount === null
        ) {
            return false;
        }

        return (float) $this->difference_amount < 0;
    }

    /**
     * Determine whether the reconciliation has a cash overage.
     */
    public function hasOverage(): bool
    {
        if (
            $this->actual_amount === null
            || $this->difference_amount === null
        ) {
            return false;
        }

        return (float) $this->difference_amount > 0;
    }

    /**
     * Determine whether the reconciliation is awaiting review.
     */
    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    /**
     * Determine whether the reconciliation has been reconciled.
     */
    public function isReconciled(): bool
    {
        return $this->status === self::STATUS_RECONCILED;
    }

    /**
     * Determine whether the reconciliation has a recorded discrepancy
     * requiring resolution.
     */
    public function isDiscrepancy(): bool
    {
        return $this->status === self::STATUS_DISCREPANCY;
    }

    /**
     * Determine whether the reconciliation was returned for correction.
     */
    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /**
     * Determine whether the reconciliation is editable by its collector.
     */
    public function isEditable(): bool
    {
        return in_array(
            $this->status,
            [
                self::STATUS_DRAFT,
                self::STATUS_RETURNED,
            ],
            true
        );
    }

    /**
     * Determine whether the reconciliation has been submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Get the difference classification.
     *
     * Returns BALANCED, SHORTAGE, OVERAGE, or NOT_RECORDED.
     */
    public function getDifferenceTypeAttribute(): string
    {
        if (
            $this->actual_amount === null
            || $this->difference_amount === null
        ) {
            return 'NOT_RECORDED';
        }

        if ((float) $this->difference_amount < 0) {
            return 'SHORTAGE';
        }

        if ((float) $this->difference_amount > 0) {
            return 'OVERAGE';
        }

        return 'BALANCED';
    }
}
