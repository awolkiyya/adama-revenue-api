<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | TABLE
    |--------------------------------------------------------------------------
    */

    protected $table = 'invoices';

    /*
    |--------------------------------------------------------------------------
    | PRIMARY KEY
    |--------------------------------------------------------------------------
    */

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    protected $fillable = [

        'invoice_number',

        'source_type',

        'assessment_id',

        'payment_schedule_id',

        'citizen_id',

        'administrative_unit_id',

        'status',

        'currency',

        'subtotal',

        'discount_amount',

        'penalty_amount',

        'interest_amount',

        'total_amount',

        'paid_amount',

        'balance_due',

        'issued_at',

        'due_date',

        'paid_at',

        'cancelled_at',

        'cancelled_by',

        'cancellation_reason',

        'voided_at',

        'voided_by',

        'void_reason',

        'notes',

        'created_by',

        'issued_by',

        'source_metadata',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [

            'subtotal' => 'decimal:4',

            'discount_amount' => 'decimal:4',

            'penalty_amount' => 'decimal:4',

            'interest_amount' => 'decimal:4',

            'total_amount' => 'decimal:4',

            'paid_amount' => 'decimal:4',

            'balance_due' => 'decimal:4',

            'issued_at' => 'datetime',

            'due_date' => 'date',

            'paid_at' => 'datetime',

            'cancelled_at' => 'datetime',

            'voided_at' => 'datetime',

            'source_metadata' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /**
     * Assessment that generated this invoice.
     *
     * Populated when:
     *
     *     source_type = ASSESSMENT
     *
     * For DIRECT_COLLECTION invoices this is NULL.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(
            Assessment::class,
            'assessment_id'
        );
    }

    /**
     * Payment schedule / installment that generated this invoice.
     *
     * Important:
     *
     * Each row in payment_schedules represents ONE installment.
     *
     * Therefore:
     *
     *     payment_schedule_id
     *             ↓
     *     payment_schedules.id
     *
     * NULL for:
     *
     * - normal assessment invoices
     * - direct collection invoices
     *
     * Populated for:
     *
     * - LIZZ / schedule-based invoices
     */
    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(
            PaymentSchedule::class,
            'payment_schedule_id'
        );
    }

    /**
     * Citizen / taxpayer who owns the invoice.
     */
    public function citizen(): BelongsTo
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
    }

    /**
     * Administrative unit responsible for the invoice.
     */
    public function administrativeUnit(): BelongsTo
    {
        return $this->belongsTo(
            AdministrativeUnit::class,
            'administrative_unit_id'
        );
    }

    /**
     * Invoice line items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            InvoiceItem::class,
            'invoice_id'
        )->orderBy(
            'line_number'
        );
    }

    /**
     * User who created the invoice.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who officially issued the invoice.
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'issued_by'
        );
    }

    /**
     * User who cancelled the invoice.
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by'
        );
    }

    /**
     * User who voided the invoice.
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'voided_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeDraft($query)
    {
        return $query->where(
            'status',
            'DRAFT'
        );
    }

    public function scopeIssued($query)
    {
        return $query->where(
            'status',
            'ISSUED'
        );
    }

    public function scopePartiallyPaid($query)
    {
        return $query->where(
            'status',
            'PARTIALLY_PAID'
        );
    }

    public function scopePaid($query)
    {
        return $query->where(
            'status',
            'PAID'
        );
    }

    public function scopeOverdue($query)
    {
        return $query->where(
            'status',
            'OVERDUE'
        );
    }

    public function scopeCancelled($query)
    {
        return $query->where(
            'status',
            'CANCELLED'
        );
    }

    public function scopeVoid($query)
    {
        return $query->where(
            'status',
            'VOID'
        );
    }

    /**
     * Invoices generated from an assessment.
     */
    public function scopeForAssessment(
        $query,
        string $assessmentId
    ) {
        return $query->where(
            'assessment_id',
            $assessmentId
        );
    }

    /**
     * Invoices generated from a specific payment schedule
     * / installment.
     */
    public function scopeForPaymentSchedule(
        $query,
        string $paymentScheduleId
    ) {
        return $query->where(
            'payment_schedule_id',
            $paymentScheduleId
        );
    }

    /**
     * Normal assessment invoices.
     *
     * Assessment invoice without a payment schedule.
     */
    public function scopeNormalAssessment($query)
    {
        return $query
            ->where('source_type', 'ASSESSMENT')
            ->whereNull('payment_schedule_id');
    }

    /**
     * Schedule-based / LIZZ invoices.
     */
    public function scopeScheduleBased($query)
    {
        return $query
            ->where('source_type', 'ASSESSMENT')
            ->whereNotNull('payment_schedule_id');
    }

    public function scopeForCitizen(
        $query,
        string $citizenId
    ) {
        return $query->where(
            'citizen_id',
            $citizenId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isIssued(): bool
    {
        return $this->status === 'ISSUED';
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === 'PARTIALLY_PAID';
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'OVERDUE';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'CANCELLED';
    }

    public function isVoid(): bool
    {
        return $this->status === 'VOID';
    }

    public function isAssessmentInvoice(): bool
    {
        return $this->source_type === 'ASSESSMENT';
    }

    public function isDirectCollection(): bool
    {
        return $this->source_type === 'DIRECT_COLLECTION';
    }

    /**
     * Determine whether this invoice belongs to a payment schedule.
     */
    public function isScheduleBased(): bool
    {
        return $this->source_type === 'ASSESSMENT'
            && $this->payment_schedule_id !== null;
    }

    /**
     * Determine whether this is a normal assessment invoice
     * without a payment schedule.
     */
    public function isNormalAssessment(): bool
    {
        return $this->source_type === 'ASSESSMENT'
            && $this->payment_schedule_id === null;
    }

    /**
     * Determine whether this invoice represents a specific
     * payment schedule installment.
     */
    public function hasPaymentSchedule(): bool
    {
        return $this->payment_schedule_id !== null;
    }

    public function isFullyPaid(): bool
    {
        return bccomp(
            (string) $this->balance_due,
            '0',
            4
        ) === 0;
    }
}
