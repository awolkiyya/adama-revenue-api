<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;


class Cluster extends Model
{

    use HasUuids;


    protected $keyType = 'string';


    public $incrementing = false;


    protected $fillable = [

        'city_id',
        'name',
        'code',
        'description',
        'is_active',

    ];



    public function city()
    {
        return $this->belongsTo(
            AdministrativeUnit::class,
            'city_id'
        );
    }

}