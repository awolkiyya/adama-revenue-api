<?php

namespace App\Models;

use App\Enums\PaymentScheduleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSchedule extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'payment_schedules';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | MASS ASSIGNMENT
    |--------------------------------------------------------------------------
    |
    | rule_percentage is a historical snapshot of the percentage rule
    | actually applied when this payment schedule was generated.
    |
    | It is NOT a live reference to the current revenue-code rule.
    |
    */

    protected $fillable = [
        'assessment_service_id',
        'installment_number',
        'rule_percentage',
        'due_date',
        'amount_due',
        'amount_paid',
        'status',
        'paid_at',
        'notes',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | Applied rule snapshot
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            |     rule_percentage = 10.00
            |
            | means the 10% first-installment rule was applied when this
            | schedule was generated.
            |
            | NULL means no percentage rule was applied.
            |
            */

            'rule_percentage' => 'decimal:2',

            'due_date' => 'date',

            /*
            |--------------------------------------------------------------------------
            | Financial amounts
            |--------------------------------------------------------------------------
            |
            | These are authoritative monetary values and use four decimal
            | places throughout the payment schedule domain.
            |
            */

            'amount_due' => 'decimal:4',

            'amount_paid' => 'decimal:4',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'status' => PaymentScheduleStatus::class,

            'paid_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => 'datetime',

            'updated_at' => 'datetime',
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
    | Assessment Service
    |--------------------------------------------------------------------------
    */

    public function assessmentService(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentService::class,
            'assessment_service_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice Items
    |--------------------------------------------------------------------------
    |
    | A payment schedule can be referenced by invoice items.
    |
    | We intentionally do not store invoice_id on payment_schedules.
    |
    | Relationship:
    |
    |     PaymentSchedule
    |          ↓
    |     InvoiceItem
    |          ↓
    |     Invoice
    |
    */

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(
            InvoiceItem::class,
            'payment_schedule_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Derived Amount
    |--------------------------------------------------------------------------
    |
    | This represents the unpaid principal remaining on this schedule.
    |
    | It does NOT include:
    |
    | - penalty
    | - interest
    | - discount
    |
    | Those belong to the financial/billing layer.
    |
    */

    public function remainingAmount(): string
    {
        $amountDue = (float) $this->amount_due;

        $amountPaid = (float) ($this->amount_paid ?? 0);

        return number_format(
            max(
                $amountDue - $amountPaid,
                0
            ),
            4,
            '.',
            ''
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payment State Helpers
    |--------------------------------------------------------------------------
    */

    public function isPaid(): bool
    {
        return $this->status === PaymentScheduleStatus::PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === PaymentScheduleStatus::CANCELLED;
    }

    public function hasRemainingAmount(): bool
    {
        return (float) $this->remainingAmount() > 0;
    }
}