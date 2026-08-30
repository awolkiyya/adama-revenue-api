<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CitizenAccount extends Model
{
    use HasUuids;


    /**
     * UUID primary key
     */
    protected $keyType = 'string';


    /**
     * Disable auto increment
     */
    public $incrementing = false;



    protected $fillable = [

        'user_id',

        'citizen_id',

        'login_type',

        'is_active',

    ];



    protected $casts = [

        'is_active' => 'boolean',

    ];



    /**
     * Login account
     *
     * citizen_accounts.user_id
     * users.id
     */
    public function user()
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }



    /**
     * Citizen profile
     *
     * citizen_accounts.citizen_id
     * citizens.id
     */
    public function citizen()
    {
        return $this->belongsTo(
            Citizen::class,
            'citizen_id'
        );
    }

}