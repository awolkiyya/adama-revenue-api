<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Payment extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'payments';


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

        /*
        |--------------------------------------------------------------------------
        | Business References
        |--------------------------------------------------------------------------
        */

        'payment_number',

        'invoice_id',

        'citizen_id',


        /*
        |--------------------------------------------------------------------------
        | Payment Classification
        |--------------------------------------------------------------------------
        */

        'payment_method',

        'payment_source',

        'status',


        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */

        'transaction_reference',


        /*
        |--------------------------------------------------------------------------
        | Financial Information
        |--------------------------------------------------------------------------
        */

        'amount',

        'currency',


        /*
        |--------------------------------------------------------------------------
        | Processing
        |--------------------------------------------------------------------------
        */

        'processed_by',


        /*
        |--------------------------------------------------------------------------
        | Verification
        |--------------------------------------------------------------------------
        */

        'verified_by',

        'verified_at',


        /*
        |--------------------------------------------------------------------------
        | Payer Snapshot
        |--------------------------------------------------------------------------
        */

        'payer_name',

        'payer_email',

        'payer_phone',


        /*
        |--------------------------------------------------------------------------
        | Failure Information
        |--------------------------------------------------------------------------
        */

        'failure_reason',


        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        'metadata',
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
            |--------------------------------------------------------------------------
            | Enums
            |--------------------------------------------------------------------------
            */

            'payment_method' => PaymentMethod::class,

            'status' => PaymentStatus::class,


            /*
            |--------------------------------------------------------------------------
            | Financial Values
            |--------------------------------------------------------------------------
            */

            'amount' => 'decimal:2',


            /*
            |--------------------------------------------------------------------------
            | JSON
            |--------------------------------------------------------------------------
            */

            'metadata' => 'array',


            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'verified_at' => 'datetime',

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
     * Invoice this payment belongs to.
     *
     * One invoice can have many payments.
     *
     * This supports partial payments.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(
            Invoice::class,
            'invoice_id'
        );
    }


    /**
     * Citizen associated with the payment.
     */
    public function citizen(): BelongsTo
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
    }


    /**
     * User who processed or recorded the payment.
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'processed_by'
        );
    }


    /**
     * User who verified the payment.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Payment Method Details
    |--------------------------------------------------------------------------
    */

    /**
     * Cash payment information.
     */
    public function cashDetails(): HasOne
    {
        return $this->hasOne(
            CashPaymentDetail::class,
            'payment_id'
        );
    }


    /**
     * Bank transfer information.
     */
    public function bankTransferDetails(): HasOne
    {
        return $this->hasOne(
            BankTransferDetail::class,
            'payment_id'
        );
    }


    /**
     * Online payment information.
     */
    public function onlineDetails(): HasOne
    {
        return $this->hasOne(
            OnlinePaymentDetail::class,
            'payment_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    /**
     * Generic files attached directly to the payment.
     *
     * Method-specific supporting documents should preferably
     * be attached to the corresponding payment detail.
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
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the payment is pending.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }


    /**
     * Determine whether the payment is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === PaymentStatus::PROCESSING;
    }


    /**
     * Determine whether the payment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }


    /**
     * Determine whether the payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED;
    }


    /**
     * Determine whether the payment was cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === PaymentStatus::CANCELLED;
    }


    /**
     * Determine whether the payment was reversed.
     */
    public function isReversed(): bool
    {
        return $this->status === PaymentStatus::REVERSED;
    }


    /**
     * Determine whether the payment successfully completed.
     */
    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }


    /**
     * Determine whether the payment has reached a final state.
     */
    public function isFinal(): bool
    {
        return in_array(
            $this->status,
            [
                PaymentStatus::COMPLETED,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::REVERSED,
            ],
            true
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Payment State Changes
    |--------------------------------------------------------------------------
    */

    /**
     * Mark payment as completed.
     *
     * Invoice financial updates must be handled by
     * PaymentService inside a database transaction.
     */
    public function markAsCompleted(
        string $verifiedBy
    ): bool {
        return $this->forceFill([
            'status' => PaymentStatus::COMPLETED,

            'verified_by' => $verifiedBy,

            'verified_at' => now(),

            'failure_reason' => null,
        ])->save();
    }


    /**
     * Mark payment as failed.
     *
     * Failed payments must not affect invoice balances.
     */
    public function markAsFailed(
        string $reason,
        string $verifiedBy
    ): bool {
        return $this->forceFill([
            'status' => PaymentStatus::FAILED,

            'failure_reason' => $reason,

            'verified_by' => $verifiedBy,

            'verified_at' => now(),
        ])->save();
    }


    /**
     * Cancel the payment.
     */
    public function markAsCancelled(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::CANCELLED,
        ])->save();
    }


    /**
     * Mark the payment as reversed.
     *
     * Any invoice balance reversal must be handled by
     * PaymentService inside a database transaction.
     */
    public function markAsReversed(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::REVERSED,
        ])->save();
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class, 'payment_id');
    }
}