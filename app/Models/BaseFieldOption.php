<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaseFieldOption extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'base_field_options';

    protected $fillable = [
        'id',
        'base_field_id',
        'value',
        'label',
        'description',
        'sort_order',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function baseField(): BelongsTo
    {
        return $this->belongsTo(
            BaseField::class,
            'base_field_id'
        );
    }
}