<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueCodePaymentScheduleRule extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'revenue_code_payment_schedule_rules';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'revenue_code_id',
        'is_enabled',
        'first_installment_percentage',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'first_installment_percentage' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Revenue code this payment schedule rule belongs to.
     */
    public function revenueCode(): BelongsTo
    {
        return $this->belongsTo(
            RevenueCode::class,
            'revenue_code_id'
        );
    }

    /**
     * Determine whether payment scheduling is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }

    /**
     * Determine whether a first-installment percentage is configured.
     */
    public function hasFirstInstallmentPercentage(): bool
    {
        return $this->first_installment_percentage !== null;
    }
}