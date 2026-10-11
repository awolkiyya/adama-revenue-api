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
    | Financial amounts and review fields should be assigned explicitly
    | by trusted backend services, not accepted blindly from requests.
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
     * Finance officer who reviewed the reconciliation.
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
     * Determine whether the reconciliation has a cash discrepancy.
     */
    public function hasDiscrepancy(): bool
    {
        return $this->actual_amount !== null
            && bccomp(
                (string) $this->difference_amount,
                '0',
                2
            ) !== 0;
    }

    /**
     * Determine whether the reconciliation is awaiting review.
     */
    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    /**
     * Determine whether the reconciliation has been approved.
     */
    public function isReconciled(): bool
    {
        return $this->status === self::STATUS_RECONCILED;
    }
}
