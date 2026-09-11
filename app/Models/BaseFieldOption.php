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

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'id',
        'base_field_id',
        'value',
        'label',
        'sort_order',
        'is_default',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'is_default' => 'boolean',
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