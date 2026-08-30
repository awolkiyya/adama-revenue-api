<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sector extends Model
{
    use HasUuids, SoftDeletes;


    protected $keyType = 'string';

    public $incrementing = false;


    protected $fillable = [
        'cluster_id',
        'name',
        'code',
        'description',
        'phone',
        'email',
        'is_active',
    ];


    public function cluster()
    {
        return $this->belongsTo(Cluster::class);
    }


    public function users()
    {
        return $this->hasMany(User::class);
    }
}