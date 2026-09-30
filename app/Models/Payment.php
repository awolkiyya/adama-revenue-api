<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Payment extends Model
{
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Primary Key
    |--------------------------------------------------------------------------
    */

    protected $keyType = 'string';

    public $incrementing = false;


    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'payments';


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

        'invoice_id',

        'payment_number',

        'assessment_id',

        'citizen_id',


        /*
        |--------------------------------------------------------------------------
        | Payment Audit
        |--------------------------------------------------------------------------
        |
        | received_by:
        | The user who recorded/received the payment.
        |
        | verified_by:
        | The user who verified, approved, or rejected the payment.
        |
        */

        'received_by',

        'verified_by',


        /*
        |--------------------------------------------------------------------------
        | Payment Classification
        |--------------------------------------------------------------------------
        */

        'payment_method',

        'payment_provider',

        'status',


        /*
        |--------------------------------------------------------------------------
        | Transaction References
        |--------------------------------------------------------------------------
        |
        | payment_number:
        | Municipal/business-facing payment identifier.
        |
        | transaction_reference:
        | Application/payment transaction reference.
        |
        | provider_reference:
        | Reference returned by the external payment provider.
        |
        */

        'transaction_reference',

        'provider_reference',


        /*
        |--------------------------------------------------------------------------
        | Financial Information
        |--------------------------------------------------------------------------
        */

        'amount',

        'currency',


        /*
        |--------------------------------------------------------------------------
        | Payment Dates
        |--------------------------------------------------------------------------
        */

        'payment_date',

        'verified_at',


        /*
        |--------------------------------------------------------------------------
        | Online Checkout
        |--------------------------------------------------------------------------
        */

        'checkout_url',


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
        | Provider / Application Data
        |--------------------------------------------------------------------------
        */

        'provider_response',

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

            'payment_provider' => PaymentProvider::class,

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

            'provider_response' => 'array',


            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'payment_date' => 'datetime',

            'verified_at' => 'datetime',
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
     * Every payment belongs to exactly one invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(
            Invoice::class,
            'invoice_id'
        );
    }


    /**
     * Assessment associated with this payment.
     *
     * Nullable because an invoice can originate from:
     *
     * - Assessment
     * - Direct Collection
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(
            Assessment::class,
            'assessment_id'
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
     * User who received or recorded the payment.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by'
        );
    }


    /**
     * User who verified, approved, or rejected the payment.
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
    | Files
    |--------------------------------------------------------------------------
    |
    | Uses the application's generic polymorphic files table.
    |
    | Supported collections:
    |
    | - payment_evidence
    | - payment_receipt
    | - payment_refund_evidence
    |
    |--------------------------------------------------------------------------
    */

    /**
     * All files attached to this payment.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(
            File::class,
            'fileable'
        );
    }


    /**
     * Payment evidence.
     *
     * Examples:
     *
     * - Bank transfer slip
     * - Bank deposit receipt
     * - POS evidence
     * - Other supporting documents
     */
    public function paymentEvidence(): MorphMany
    {
        return $this->morphMany(
            File::class,
            'fileable'
        )->where(
            'collection',
            'payment_evidence'
        );
    }


    /**
     * Official receipts generated by the system.
     */
    public function receipts(): MorphMany
    {
        return $this->morphMany(
            File::class,
            'fileable'
        )->where(
            'collection',
            'payment_receipt'
        );
    }


    /**
     * Refund evidence.
     *
     * Used when a successful payment is refunded.
     */
    public function refundEvidence(): MorphMany
    {
        return $this->morphMany(
            File::class,
            'fileable'
        )->where(
            'collection',
            'payment_refund_evidence'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the payment is pending.
     *
     * PENDING means the payment has been recorded but
     * has not yet been successfully verified/completed.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }


    /**
     * Determine whether the payment was successfully completed.
     */
    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::SUCCESS;
    }


    /**
     * Determine whether the payment failed or was rejected.
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
     * Determine whether the payment was refunded.
     */
    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::REFUNDED;
    }


    /**
     * Determine whether the payment has reached a final state.
     *
     * SUCCESS, FAILED, CANCELLED and REFUNDED are treated
     * as final payment states.
     */
    public function isFinal(): bool
    {
        return in_array(
            $this->status,
            [
                PaymentStatus::SUCCESS,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::REFUNDED,
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
     * Mark the payment as successfully verified.
     *
     * IMPORTANT:
     *
     * This method changes only the payment record.
     *
     * It does NOT update:
     *
     * - invoice.paid_amount
     * - invoice.balance_due
     * - invoice.status
     *
     * Those financial changes must be handled by PaymentService
     * inside a database transaction.
     */
    public function markAsVerified(
        string $verifiedBy
    ): bool {
        return $this->forceFill([
            'status' => PaymentStatus::SUCCESS,

            'verified_by' => $verifiedBy,

            'verified_at' => now(),

            'failure_reason' => null,
        ])->save();
    }


    /**
     * Reject / fail the payment.
     *
     * A failed payment MUST NOT affect invoice financial totals.
     *
     * The rejection is recorded with:
     *
     * - rejection reason
     * - user who reviewed/rejected it
     * - review timestamp
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
     *
     * FAILED:
     * The payment was reviewed and could not be accepted.
     *
     * CANCELLED:
     * The payment process was intentionally cancelled or
     * abandoned before successful completion.
     *
     * Existing audit information is preserved.
     */
    public function markAsCancelled(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::CANCELLED,
        ])->save();
    }


    /**
     * Mark the payment as refunded.
     *
     * The actual invoice balance reversal must be handled
     * by PaymentService inside a database transaction.
     *
     * Existing payment verification information is preserved
     * for audit purposes.
     */
    public function markAsRefunded(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::REFUNDED,
        ])->save();
    }
}