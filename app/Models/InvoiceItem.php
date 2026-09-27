<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceItem extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | TABLE
    |--------------------------------------------------------------------------
    */

    protected $table = 'invoice_items';

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

        'invoice_id',

        'assessment_service_id',

        /*
        |--------------------------------------------------------------------------
        | PAYMENT SCHEDULE
        |--------------------------------------------------------------------------
        |
        | Nullable.
        |
        | Used when this invoice item represents a specific scheduled
        | installment/payment schedule.
        |
        */

        'payment_schedule_id',

        'service_id',

        'line_number',

        'description',

        'quantity',

        'unit',

        'unit_price',

        'amount',

        'discount_amount',

        'penalty_amount',

        'interest_amount',

        'total_amount',

        'currency',

        'tariff_version_id',

        'tariff_rule_id',

        'input_snapshot',

        'calculation_snapshot',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [

            'quantity' => 'decimal:4',

            'unit_price' => 'decimal:4',

            'amount' => 'decimal:4',

            'discount_amount' => 'decimal:4',

            'penalty_amount' => 'decimal:4',

            'interest_amount' => 'decimal:4',

            'total_amount' => 'decimal:4',

            'input_snapshot' => 'array',

            'calculation_snapshot' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /**
     * Invoice that owns this line.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(
            Invoice::class,
            'invoice_id'
        );
    }

    /**
     * Original assessment service.
     *
     * Nullable for DIRECT_COLLECTION invoices.
     */
    public function assessmentService(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentService::class,
            'assessment_service_id'
        );
    }

    /**
     * Payment schedule represented by this invoice item.
     *
     * Nullable because:
     *
     * - ONE_TIME assessment items do not have a payment schedule.
     * - DIRECT_COLLECTION items do not have a payment schedule.
     * - SCHEDULED assessment items reference the specific installment
     *   being invoiced.
     */
    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(
            PaymentSchedule::class,
            'payment_schedule_id'
        );
    }

    /**
     * Revenue service being charged.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(
            RevenueService::class,
            'service_id'
        );
    }

    /**
     * Tariff version used for this calculation.
     */
    public function tariffVersion(): BelongsTo
    {
        return $this->belongsTo(
            TariffVersion::class,
            'tariff_version_id'
        );
    }

    /**
     * Exact tariff rule used for this calculation.
     */
    public function tariffRule(): BelongsTo
    {
        return $this->belongsTo(
            TariffRule::class,
            'tariff_rule_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeForInvoice(
        $query,
        string $invoiceId
    ) {
        return $query->where(
            'invoice_id',
            $invoiceId
        );
    }

    public function scopeForService(
        $query,
        string $serviceId
    ) {
        return $query->where(
            'service_id',
            $serviceId
        );
    }

    /**
     * Scope invoice items belonging to a specific assessment service.
     */
    public function scopeForAssessmentService(
        $query,
        string $assessmentServiceId
    ) {
        return $query->where(
            'assessment_service_id',
            $assessmentServiceId
        );
    }

    /**
     * Scope invoice items belonging to a specific payment schedule.
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

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this item belongs to an assessment service.
     */
    public function hasAssessmentService(): bool
    {
        return ! is_null(
            $this->assessment_service_id
        );
    }

    /**
     * Determine whether this item represents a payment schedule.
     */
    public function hasPaymentSchedule(): bool
    {
        return ! is_null(
            $this->payment_schedule_id
        );
    }

    /**
     * Determine whether this is a scheduled assessment item.
     */
    public function isScheduledItem(): bool
    {
        return
            $this->hasAssessmentService()
            &&
            $this->hasPaymentSchedule();
    }

    /**
     * Determine whether this is a direct collection item.
     */
    public function isDirectCollectionItem(): bool
    {
        return
            is_null($this->assessment_service_id)
            &&
            is_null($this->payment_schedule_id);
    }

    /**
     * Determine whether this is a one-time assessment item.
     */
    public function isOneTimeAssessmentItem(): bool
    {
        return
            $this->hasAssessmentService()
            &&
            ! $this->hasPaymentSchedule();
    }

    /**
     * Determine whether a tariff snapshot exists.
     */
    public function hasTariffSnapshot(): bool
    {
        return
            ! is_null($this->tariff_version_id)
            ||
            ! is_null($this->tariff_rule_id);
    }

    /**
     * Determine whether a calculation snapshot exists.
     */
    public function hasCalculationSnapshot(): bool
    {
        return ! is_null(
            $this->calculation_snapshot
        );
    }

    /**
     * Determine whether an input snapshot exists.
     */
    public function hasInputSnapshot(): bool
    {
        return ! is_null(
            $this->input_snapshot
        );
    }
}