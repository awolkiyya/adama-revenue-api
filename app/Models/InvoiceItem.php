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

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function hasAssessmentService(): bool
    {
        return ! is_null(
            $this->assessment_service_id
        );
    }

    public function hasTariffSnapshot(): bool
    {
        return
            ! is_null($this->tariff_version_id)
            ||
            ! is_null($this->tariff_rule_id);
    }

    public function hasCalculationSnapshot(): bool
    {
        return ! is_null(
            $this->calculation_snapshot
        );
    }

    public function hasInputSnapshot(): bool
    {
        return ! is_null(
            $this->input_snapshot
        );
    }
}
