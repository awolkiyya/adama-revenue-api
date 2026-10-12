<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
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
        // Business references
        'payment_number',
        'request_id',
        'invoice_id',
        'citizen_id',

        // Payment classification
        'payment_method',
        'payment_provider',
        'payment_source',
        'status',

        // Transaction references
        'transaction_reference',
        'provider_reference',

        // Financial information
        'amount',
        'currency',

        // Processing
        'processed_by',

        // Verification
        'verified_by',
        'verified_at',

        // Payer snapshot
        'payer_name',
        'payer_email',
        'payer_phone',

        // Provider and checkout
        'checkout_url',
        'provider_response',

        // Failure information
        'failure_reason',

        // Additional metadata
        'metadata',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            // Enums
            'payment_method' => PaymentMethod::class,
            'payment_provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,

            // Financial values
            'amount' => 'decimal:2',

            // JSON fields
            'metadata' => 'array',
            'provider_response' => 'array',

            // Dates
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
     * Invoice associated with this payment.
     *
     * An invoice can have multiple payments, including partial payments.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Citizen associated with this payment.
     */
    public function citizen(): BelongsTo
    {
        return $this->belongsTo(Citizen::class, 'citizen_id');
    }

    /**
     * User who recorded or processed the payment.
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * User who verified the payment.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Method Details
    |--------------------------------------------------------------------------
    */

    /**
     * Cash-specific payment information.
     */
    public function cashDetails(): HasOne
    {
        return $this->hasOne(CashPaymentDetail::class, 'payment_id');
    }

    /**
     * Bank-transfer-specific payment information.
     */
    public function bankTransferDetails(): HasOne
    {
        return $this->hasOne(BankTransferDetail::class, 'payment_id');
    }

    /**
     * Online-payment-specific information.
     */
    public function onlineDetails(): HasOne
    {
        return $this->hasOne(OnlinePaymentDetail::class, 'payment_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    /**
     * Files attached directly to the payment.
     *
     * Method-specific supporting documents can instead be attached
     * to their respective payment-detail models.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /*
    |--------------------------------------------------------------------------
    | Receipt
    |--------------------------------------------------------------------------
    */

    /**
     * Receipt generated for this payment.
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class, 'payment_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === PaymentStatus::PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === PaymentStatus::CANCELLED;
    }

    public function isReversed(): bool
    {
        return $this->status === PaymentStatus::REVERSED;
    }

    /**
     * Determine whether the payment was successfully completed.
     */
    public function isSuccessful(): bool
    {
        return $this->isCompleted();
    }

    /**
     * Determine whether the payment has reached a final state.
     *
     * Pending and processing payments are not final.
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
     * Mark a payment as completed.
     *
     * Invoice balances and payment totals must be updated by the
     * appropriate payment service within a database transaction.
     */
    public function markAsCompleted(string $verifiedBy): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::COMPLETED,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    /**
     * Mark a payment as failed.
     *
     * Failed payments must not increase the amount paid on an invoice.
     */
    public function markAsFailed(
        string $reason,
        ?string $verifiedBy = null
    ): bool {
        return $this->forceFill([
            'status' => PaymentStatus::FAILED,
            'failure_reason' => $reason,
            'verified_by' => $verifiedBy,
            'verified_at' => $verifiedBy !== null ? now() : null,
        ])->save();
    }

    /**
     * Cancel a payment.
     *
     * Any necessary invoice adjustments must be handled by the
     * appropriate payment service within a database transaction.
     */
    public function markAsCancelled(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::CANCELLED,
        ])->save();
    }

    /**
     * Mark a payment as reversed.
     *
     * The associated invoice balance must be adjusted by the payment
     * service within the same database transaction.
     */
    public function markAsReversed(): bool
    {
        return $this->forceFill([
            'status' => PaymentStatus::REVERSED,
        ])->save();
    }
}