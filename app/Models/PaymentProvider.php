<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'fee_percentage',
        'is_active',
    ];

    protected $casts = [
        'fee_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}