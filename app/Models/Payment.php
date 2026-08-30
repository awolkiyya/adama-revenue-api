<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'invoice_id',
        'user_id',
        'citizen_id',

        'payment_method',
        'payment_provider',
        'status',

        'transaction_reference',
        'provider_reference',

        'amount',
        'currency',

        'payer_name',
        'payer_email',
        'payer_phone',

        'checkout_url',

        'failure_reason',

        'metadata',
        'provider_response',

        'payment_date',
        'verified_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'payment_method' =>
                PaymentMethod::class,

            'payment_provider' =>
                PaymentProvider::class,

            'status' =>
                PaymentStatus::class,

            'amount' =>
                'decimal:2',

            'metadata' =>
                'array',

            'provider_response' =>
                'array',

            'payment_date' =>
                'datetime',

            'verified_at' =>
                'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(
            Invoice::class,
            'invoice_id'
        );
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
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

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED;
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Failed
    |--------------------------------------------------------------------------
    */

    public function markAsFailed(
        ?string $reason = null
    ): bool {
        return $this->forceFill([
            'status' =>
                PaymentStatus::FAILED,

            'failure_reason' =>
                $reason,
        ])->save();
    }
}