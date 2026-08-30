<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TariffFormulaVariable extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tariff_rule_id',
        'code',
        'variable_name',
        'label',
        'source_type',
        'base_field_id',
        'default_value',
        'data_type',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'default_value' => 'decimal:4',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function tariffRule(): BelongsTo
    {
        return $this->belongsTo(
            TariffRule::class,
            'tariff_rule_id'
        );
    }

    public function baseField(): BelongsTo
    {
        return $this->belongsTo(
            BaseField::class,
            'base_field_id'
        );
    }
}