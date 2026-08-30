<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Citizen extends Model
{
    use HasUuids, SoftDeletes;


    protected $keyType = 'string';

    public $incrementing = false;



    protected $fillable = [

        'id',

        'citizen_uid',

        'full_name',

        'phone',

        'email',

        'national_id',

        'address',

        'gender',

        'date_of_birth',

        'administrative_unit_id',

        'registered_sector_id',

        'created_by',

        'updated_by',

        'registered_at',

        'source',

        'external_id',

        'is_active',

    ];



    protected $casts = [

        'date_of_birth' => 'date',

        'registered_at' => 'datetime',

        'is_active' => 'boolean',

    ];



    /*
    |--------------------------------------------------------------------------
    | Administrative Unit
    |--------------------------------------------------------------------------
    */

    public function administrativeUnit()
    {
        return $this->belongsTo(
            AdministrativeUnit::class,
            'administrative_unit_id'
        );
    }



    /*
    |--------------------------------------------------------------------------
    | Registered Sector
    |--------------------------------------------------------------------------
    */

    public function registeredSector()
    {
        return $this->belongsTo(
            Sector::class,
            'registered_sector_id'
        );
    }



    /*
    |--------------------------------------------------------------------------
    | Created By User
    |--------------------------------------------------------------------------
    */

    public function createdBy()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }



    /*
    |--------------------------------------------------------------------------
    | Updated By User
    |--------------------------------------------------------------------------
    */

    public function updatedBy()
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }



    /*
    |--------------------------------------------------------------------------
    | Avatar
    |--------------------------------------------------------------------------
    */

   /*
|--------------------------------------------------------------------------
| Avatar
|--------------------------------------------------------------------------
*/

public function avatar()
{
    return $this->morphOne(
        File::class,
        'fileable'
    )->where(
        'collection',
        'AVATAR'
    );
}


    /*
    |--------------------------------------------------------------------------
    | Citizen Account
    |--------------------------------------------------------------------------
    */

    public function account()
    {
        return $this->hasOne(
            CitizenAccount::class,
            'citizen_id'
        );
    }

}