<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;


    /**
     * UUID primary key
     */
    protected $keyType = 'string';


    public $incrementing = false;



    /**
     * Mass assignable fields
     */
    protected $fillable = [

        'user_id',

        'action',

        'module',

        'table_name',

        'record_id',

        'old_values',

        'new_values',

        'ip_address',

        'user_agent',

        'description',

    ];



    /**
     * Cast JSON fields
     */
    protected $casts = [

        'old_values' => 'array',

        'new_values' => 'array',

    ];



    /**
     * User who performed action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }



    /**
     * Scope:
     * Filter by module
     */
    public function scopeModule(
        $query,
        string $module
    ) {

        return $query->where(
            'module',
            $module
        );

    }



    /**
     * Scope:
     * Filter by action
     */
    public function scopeAction(
        $query,
        string $action
    ) {

        return $query->where(
            'action',
            $action
        );

    }



    /**
     * Prevent modification
     *
     * Audit data should not change
     */
    protected static function booted()
    {

        static::updating(function () {

            throw new \Exception(
                'Audit logs cannot be modified.'
            );

        });


        static::deleting(function () {

            throw new \Exception(
                'Audit logs cannot be deleted.'
            );

        });

    }

}