<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlinePaymentDetail extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'online_payment_details';

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
        'payment_id',
        'payment_provider_id',
        'checkout_reference',
        'provider_transaction_id',
        'checkout_url',
        'provider_status',
        'callback_received_at',
        'provider_response',
        'paid_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'callback_received_at' => 'datetime',
            'paid_at' => 'datetime',
            'provider_response' => 'array',
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
    | Payment
    |--------------------------------------------------------------------------
    */

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            Payment::class,
            'payment_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    */

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(
            PaymentProvider::class,
            'payment_provider_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public function isPaid(): bool
    {
        return ! is_null($this->paid_at);
    }

    public function isPending(): bool
    {
        return strtoupper((string) $this->provider_status) === 'PENDING';
    }

    public function isSuccessful(): bool
    {
        return in_array(
            strtoupper((string) $this->provider_status),
            [
                'SUCCESS',
                'SUCCEEDED',
                'COMPLETED',
                'PAID',
            ],
            true
        );
    }

    public function isFailed(): bool
    {
        return in_array(
            strtoupper((string) $this->provider_status),
            [
                'FAILED',
                'CANCELLED',
                'CANCELED',
            ],
            true
        );
    }
}