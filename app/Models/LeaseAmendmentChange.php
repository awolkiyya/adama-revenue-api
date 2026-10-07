<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseAmendmentChange extends Model
{
    protected $table = 'lease_amendment_changes';

    protected $fillable = [
        'lease_amendment_id',
        'field_name',
        'value_type',
        'old_value',
        'new_value',
        'measurement_unit_id',
        'reason',
        'change_order',
    ];

    protected $casts = [
        'old_value' => 'json',
        'new_value' => 'json',
    ];

    public function leaseAmendment(): BelongsTo
    {
        return $this->belongsTo(
            LeaseAmendment::class,
            'lease_amendment_id'
        );
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementUnit::class,
            'measurement_unit_id'
        );
    }
}