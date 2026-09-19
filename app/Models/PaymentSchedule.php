<?php

namespace App\Models;

use App\Enums\PaymentScheduleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSchedule extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'payment_schedules';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'assessment_service_id',
        'installment_number',
        'due_date',
        'amount_due',
        'amount_paid',
        'status',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',

            'due_date' => 'date',

            'amount_due' => 'decimal:4',

            'amount_paid' => 'decimal:4',

            'status' => PaymentScheduleStatus::class,

            'paid_at' => 'datetime',

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
}
