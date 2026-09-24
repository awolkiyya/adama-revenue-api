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
        | Calculation Output
        |--------------------------------------------------------------------------
        */

        'computed_amount',
        'currency_code',
        'calculation_metadata',
        'calculation_error',
        'calculated_at',

        /*
        |--------------------------------------------------------------------------
        | Historical Financial Position
        |--------------------------------------------------------------------------
        |
        | Used by Existing LIZZ agreements.
        |
        | computed_amount
        |     = Original Obligation
        |
        | paid_amount
        |     = Amount Already Paid
        |
        | remaining_amount
        |     = Outstanding Historical Balance
        |
        | balance_as_of_date
        |     = Date on which the historical financial
        |       position was established/confirmed.
        |
        */

        'paid_amount',
        'remaining_amount',
        'balance_as_of_date',

        /*
        |--------------------------------------------------------------------------
        | Payment Obligation
        |--------------------------------------------------------------------------
        */

        'due_date',
        'agreement_date',

        /*
        |--------------------------------------------------------------------------
        | Applied Financial Rules
        |--------------------------------------------------------------------------
        */

        'penalty_rule_id',
        'interest_rule_id',

        /*
        |--------------------------------------------------------------------------
        | Payment Tracking
        |--------------------------------------------------------------------------
        |
        | paid_principal_amount is kept separately because it belongs
        | to the normal payment/accounting workflow.
        |
        */

        'payment_status',
        'paid_principal_amount',
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
            | Ordering
            |--------------------------------------------------------------------------
            */

            'service_order' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | Amounts
            |--------------------------------------------------------------------------
            */

            'computed_amount' => 'decimal:4',

            /*
             * Existing LIZZ:
             *
             * Amount already paid before system registration.
             */
            'paid_amount' => 'decimal:4',

            /*
             * Existing LIZZ:
             *
             * Outstanding historical balance.
             */
            'remaining_amount' => 'decimal:4',

            /*
             * Normal payment workflow:
             *
             * Principal actually paid through payments.
             */
            'paid_principal_amount' => 'decimal:4',

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'balance_as_of_date' => 'date',
            'due_date' => 'date',
            'agreement_date' => 'date',
            'calculated_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | JSON
            |--------------------------------------------------------------------------
            */

            'calculation_metadata' => 'array',

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
    | Penalty Rule
    |--------------------------------------------------------------------------
    */

    public function penaltyRule(): BelongsTo
    {
        return $this->belongsTo(
            PenaltyRule::class,
            'penalty_rule_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Interest Rule
    |--------------------------------------------------------------------------
    */

    public function interestRule(): BelongsTo
    {
        return $this->belongsTo(
            InterestRule::class,
            'interest_rule_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Captured Field Values
    |--------------------------------------------------------------------------
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
    | Payment Schedules
    |--------------------------------------------------------------------------
    |
    | assessment_services.id
    |              ↓
    | payment_schedules.assessment_service_id
    |
    | One assessment service can have multiple
    | installment payment schedules.
    |
    */

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(
            PaymentSchedule::class,
            'assessment_service_id'
        )->orderBy('installment_number');
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
    | Payment Status Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeUnpaid(
        Builder $query
    ): Builder {
        return $query->where(
            'payment_status',
            'UNPAID'
        );
    }

    public function scopePartiallyPaid(
        Builder $query
    ): Builder {
        return $query->where(
            'payment_status',
            'PARTIALLY_PAID'
        );
    }

    public function scopePaid(
        Builder $query
    ): Builder {
        return $query->where(
            'payment_status',
            'PAID'
        );
    }

    public function scopeWaived(
        Builder $query
    ): Builder {
        return $query->where(
            'payment_status',
            'WAIVED'
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

    /*
    |--------------------------------------------------------------------------
    | Payment Helpers
    |--------------------------------------------------------------------------
    */

    public function isUnpaid(): bool
    {
        return $this->payment_status === 'UNPAID';
    }

    public function isPartiallyPaid(): bool
    {
        return $this->payment_status === 'PARTIALLY_PAID';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'PAID';
    }

    public function isWaived(): bool
    {
        return $this->payment_status === 'WAIVED';
    }

    /*
    |--------------------------------------------------------------------------
    | Financial Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get the outstanding historical balance.
     *
     * Primarily used for Existing LIZZ agreements.
     *
     * Formula:
     *
     * computed_amount - paid_amount
     */
    public function getOutstandingHistoricalBalanceAttribute(): float
    {
        return max(
            0,
            (float) $this->computed_amount -
            (float) $this->paid_amount
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rule Helpers
    |--------------------------------------------------------------------------
    */

    public function hasPenaltyRule(): bool
    {
        return ! is_null($this->penalty_rule_id);
    }

    public function hasInterestRule(): bool
    {
        return ! is_null($this->interest_rule_id);
    }
}
