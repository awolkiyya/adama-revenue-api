<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class OtpVerification extends Model
{

    use HasUuids;


    protected $table = 'otp_verifications';



    /**
     * UUID primary key
     */
    protected $keyType = 'string';


    public $incrementing = false;



    /**
     * Mass assignable fields
     */
    protected $fillable = [

        'id',

        'user_id',

        'phone',

        'code',

        'type',

        'expires_at',

        'verified_at',

        'attempts',

        'ip_address',

        'user_agent',

        'is_used',

    ];



    /**
     * Type casting
     */
    protected function casts(): array
    {
        return [

            'expires_at' => 'datetime',

            'verified_at' => 'datetime',

            'is_used' => 'boolean',

        ];
    }



    /**
     * User relation
     *
     * Existing officer/citizen login account
     */
    public function user(): BelongsTo
    {

        return $this->belongsTo(
            User::class
        );

    }

}