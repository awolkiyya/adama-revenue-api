<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RevenueCategory extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'revenue_domain',
        'name',
        'start_code',
        'end_code',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'start_code' => 'integer',
        'end_code'   => 'integer',
        'sort_order' => 'integer',
    ];

    public function codes()
    {
        return $this->hasMany(RevenueCode::class, 'category_id')
            ->orderBy('code');
    }
}