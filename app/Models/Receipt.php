<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'receipts';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payment_id',
        'receipt_number',
        'issued_by',
        'issued_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The payment for which this receipt was issued.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * The user who issued the receipt.
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Determine whether the receipt is currently issued.
     */
    public function isIssued(): bool
    {
        return $this->status === 'ISSUED';
    }

    /**
     * Determine whether the receipt has been voided.
     */
    public function isVoided(): bool
    {
        return $this->status === 'VOIDED';
    }

    /**
     * Mark the receipt as voided.
     */
    public function void(): bool
    {
        return $this->forceFill([
            'status' => 'VOIDED',
        ])->save();
    }
}