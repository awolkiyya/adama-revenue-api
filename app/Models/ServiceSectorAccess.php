<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceSectorAccess extends Model
{
    use HasUuids, SoftDeletes;


    protected $keyType = 'string';


    public $incrementing = false;


    protected $fillable = [

        'service_id',

        'sector_id',

        'permission',

        'is_active',

    ];



    public function service()
    {
        return $this->belongsTo(
            RevenueService::class,
            'service_id'
        );
    }



    public function sector()
    {
        return $this->belongsTo(
            Sector::class,
            'sector_id'
        );
    }



    public function creator()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }



    public function updater()
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

}