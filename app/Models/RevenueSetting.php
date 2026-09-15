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
        | Annual Payment Due Date
        |--------------------------------------------------------------------------
        |
        | Stored as Ethiopian recurring month/day:
        |
        |     MM-DD
        |
        | Examples:
        |
        |     01-01
        |     10-30
        |     13-06
        |
        */

        'annual_payment_due_date',

        /*
        |--------------------------------------------------------------------------
        | Penalty / Interest
        |--------------------------------------------------------------------------
        */

        'penalty_enabled',
        'interest_enabled',

        /*
        |--------------------------------------------------------------------------
        | Lizz
        |--------------------------------------------------------------------------
        |
        | Global Lizz policy configuration.
        |
        | This percentage is used when an assessment specifies:
        |
        |     first_installment_required = true
        |
        | Example:
        |
        |     10.00 = 10%
        |
        */

        'lizz_first_installment_percentage',

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
            | Annual Payment Due Date
            |--------------------------------------------------------------------------
            */

            'annual_payment_due_date' => 'string',

            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' => 'boolean',
            'interest_enabled' => 'boolean',

            /*
            |--------------------------------------------------------------------------
            | Lizz
            |--------------------------------------------------------------------------
            */

            'lizz_first_installment_percentage' => 'decimal:2',

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
    | Annual Payment Due Date
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether an annual payment due date is configured.
     *
     * The value is expected to be stored as MM-DD.
     */
    public function hasAnnualPaymentDueDate(): bool
    {
        return $this->annual_payment_due_date !== null
            && trim($this->annual_payment_due_date) !== '';
    }


    /**
     * Get the configured annual payment due date.
     *
     * Returns the persisted MM-DD value.
     */
    public function annualPaymentDueDate(): ?string
    {
        if (! $this->hasAnnualPaymentDueDate()) {
            return null;
        }

        return trim($this->annual_payment_due_date);
    }


    /*
    |--------------------------------------------------------------------------
    | Lizz
    |--------------------------------------------------------------------------
    */


    /**
     * Determine whether a Lizz first-installment percentage is configured.
     */
    public function hasLizzFirstInstallmentPercentage(): bool
    {
        return $this->lizz_first_installment_percentage !== null
            && (float) $this->lizz_first_installment_percentage > 0;
    }


    /**
     * Get the configured Lizz first-installment percentage.
     *
     * Example:
     *
     *     10.00
     *
     * means 10% of the calculated Lizz total.
     */
    public function lizzFirstInstallmentPercentage(): ?float
    {
        if (! $this->hasLizzFirstInstallmentPercentage()) {
            return null;
        }

        return (float) $this->lizz_first_installment_percentage;
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
