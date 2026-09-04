<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueSetting extends Model
{
    use HasFactory, HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'revenue_settings';


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
    |
    | Only fields that actually exist in revenue_settings are listed here.
    |
    */

    protected $fillable = [

        /*
        |--------------------------------------------------------------------------
        | Payment Period
        |--------------------------------------------------------------------------
        */

        'payment_start_month',
        'payment_start_day',
        'payment_end_month',
        'payment_end_day',

        /*
        |--------------------------------------------------------------------------
        | Penalty / Interest
        |--------------------------------------------------------------------------
        */

        'penalty_enabled',
        'interest_enabled',

        /*
        |--------------------------------------------------------------------------
        | Assessment
        |--------------------------------------------------------------------------
        */

        'assessment_auto_calculation',
        'assessment_allow_manual_adjustment',
        'assessment_requires_approval',
        'assessment_reassessment_allowed',

        /*
        |--------------------------------------------------------------------------
        | Invoice
        |--------------------------------------------------------------------------
        */

        'invoice_auto_numbering',
        'invoice_prefix',
        'invoice_allow_overpayment',
        'invoice_allow_overdue_payment',

        /*
        |--------------------------------------------------------------------------
        | Payment
        |--------------------------------------------------------------------------
        */

        'payment_confirmation_required',
        'payment_auto_receipt',
        'enabled_payment_methods',

        /*
        |--------------------------------------------------------------------------
        | Receipt
        |--------------------------------------------------------------------------
        */

        'receipt_auto_numbering',
        'receipt_prefix',
        'receipt_allow_reprint',

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        'is_active',

        /*
        |--------------------------------------------------------------------------
        | Legal / Description
        |--------------------------------------------------------------------------
        */

        'legal_reference',
        'description',

        /*
        |--------------------------------------------------------------------------
        | Audit
        |--------------------------------------------------------------------------
        */

        'created_by',
        'updated_by',
    ];


    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    |
    | PostgreSQL jsonb enabled_payment_methods is automatically converted
    | to/from a PHP array.
    |
    */

    protected function casts(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Payment Period
            |--------------------------------------------------------------------------
            */

            'payment_start_month' => 'integer',
            'payment_start_day' => 'integer',
            'payment_end_month' => 'integer',
            'payment_end_day' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' => 'boolean',
            'interest_enabled' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            'assessment_auto_calculation' => 'boolean',
            'assessment_allow_manual_adjustment' => 'boolean',
            'assessment_requires_approval' => 'boolean',
            'assessment_reassessment_allowed' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_auto_numbering' => 'boolean',
            'invoice_allow_overpayment' => 'boolean',
            'invoice_allow_overdue_payment' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_confirmation_required' => 'boolean',
            'payment_auto_receipt' => 'boolean',
            'enabled_payment_methods' => 'array',

            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_auto_numbering' => 'boolean',
            'receipt_allow_reprint' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => 'boolean',

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
    | Relationships
    |--------------------------------------------------------------------------
    */


    /**
     * User who created this revenue configuration.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }


    /**
     * User who last updated this revenue configuration.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }


    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */


    /**
     * Scope to the active global configuration.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }


    /*
    |--------------------------------------------------------------------------
    | Active Configuration
    |--------------------------------------------------------------------------
    */


    /**
     * Get the currently active revenue configuration.
     *
     * The database guarantees that only one active configuration
     * can exist at a time.
     */
    public static function active(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->first();
    }


    /**
     * Get the active configuration or fail.
     *
     * Useful for services where a valid global configuration
     * is mandatory.
     */
    public static function activeOrFail(): self
    {
        return static::query()
            ->where('is_active', true)
            ->firstOrFail();
    }


    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether a payment method is globally enabled.
     *
     * Comparison is case-insensitive.
     */
    public function paymentMethodEnabled(string $method): bool
    {
        $method = strtoupper(trim($method));

        return in_array(
            $method,
            array_map(
                static fn ($value) => strtoupper(trim((string) $value)),
                $this->enabled_payment_methods ?? []
            ),
            true
        );
    }


    /**
     * Get all globally enabled payment methods.
     */
    public function enabledPaymentMethods(): array
    {
        return array_values(
            array_map(
                static fn ($value) => strtoupper(trim((string) $value)),
                $this->enabled_payment_methods ?? []
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Payment Period
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether a global payment period is configured.
     */
    public function hasPaymentPeriod(): bool
    {
        return
            $this->payment_start_month !== null &&
            $this->payment_start_day !== null &&
            $this->payment_end_month !== null &&
            $this->payment_end_day !== null;
    }


    /**
     * Return the configured payment start period.
     *
     * Example:
     *
     * [
     *     'month' => 1,
     *     'day'   => 1,
     * ]
     */
    public function paymentStartPeriod(): ?array
    {
        if (
            $this->payment_start_month === null ||
            $this->payment_start_day === null
        ) {
            return null;
        }

        return [
            'month' => (int) $this->payment_start_month,
            'day' => (int) $this->payment_start_day,
        ];
    }


    /**
     * Return the configured payment end period.
     *
     * Example:
     *
     * [
     *     'month' => 13,
     *     'day'   => 5,
     * ]
     */
    public function paymentEndPeriod(): ?array
    {
        if (
            $this->payment_end_month === null ||
            $this->payment_end_day === null
        ) {
            return null;
        }

        return [
            'month' => (int) $this->payment_end_month,
            'day' => (int) $this->payment_end_day,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Penalty / Interest
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether penalty calculation is globally enabled.
     *
     * The actual penalty rules/rates are stored separately.
     */
    public function penaltyCalculationEnabled(): bool
    {
        return $this->penalty_enabled === true;
    }


    /**
     * Determine whether interest calculation is globally enabled.
     *
     * The actual interest rules/rates are stored separately.
     */
    public function interestCalculationEnabled(): bool
    {
        return $this->interest_enabled === true;
    }


    /*
    |--------------------------------------------------------------------------
    | Assessment
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether automatic assessment calculation is enabled.
     */
    public function assessmentAutoCalculationEnabled(): bool
    {
        return $this->assessment_auto_calculation === true;
    }


    /**
     * Determine whether manual assessment adjustment is allowed.
     */
    public function manualAssessmentAdjustmentAllowed(): bool
    {
        return $this->assessment_allow_manual_adjustment === true;
    }


    /**
     * Determine whether assessment approval is required.
     */
    public function assessmentApprovalRequired(): bool
    {
        return $this->assessment_requires_approval === true;
    }


    /**
     * Determine whether reassessment is allowed.
     */
    public function reassessmentAllowed(): bool
    {
        return $this->assessment_reassessment_allowed === true;
    }


    /*
    |--------------------------------------------------------------------------
    | Invoice
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether invoice numbering is automatic.
     */
    public function invoiceAutoNumberingEnabled(): bool
    {
        return $this->invoice_auto_numbering === true;
    }


    /**
     * Determine whether invoice overpayment is allowed.
     */
    public function overpaymentAllowed(): bool
    {
        return $this->invoice_allow_overpayment === true;
    }


    /**
     * Determine whether overdue invoice payment is allowed.
     */
    public function overduePaymentAllowed(): bool
    {
        return $this->invoice_allow_overdue_payment === true;
    }


    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether payment confirmation is required.
     */
    public function paymentConfirmationRequired(): bool
    {
        return $this->payment_confirmation_required === true;
    }


    /**
     * Determine whether receipts should be generated automatically
     * after payment processing.
     */
    public function autoReceiptEnabled(): bool
    {
        return $this->payment_auto_receipt === true;
    }


    /*
    |--------------------------------------------------------------------------
    | Receipt
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether receipt numbering is automatic.
     */
    public function receiptAutoNumberingEnabled(): bool
    {
        return $this->receipt_auto_numbering === true;
    }


    /**
     * Determine whether receipt reprinting is allowed.
     */
    public function receiptReprintAllowed(): bool
    {
        return $this->receipt_allow_reprint === true;
    }
}