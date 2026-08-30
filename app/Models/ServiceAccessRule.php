<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

use Spatie\Permission\Models\Role;



class ServiceAccessRule extends Model
{

    use HasUuids, SoftDeletes;



    protected $table = 'service_access_rules';



    protected $primaryKey = 'id';



    public $incrementing = false;



    protected $keyType = 'string';



    protected $fillable = [

        'service_id',

        'sector_id',

        'role_id',

        'actions',

        'is_active',

        'created_by',

        'updated_by',

    ];



    protected $casts = [

        /**
         * JSON actions array
         *
         * Example:
         *
         * [
         *   "CREATE",
         *   "APPROVE"
         * ]
         */
        'actions' => 'array',


        'is_active' => 'boolean',

    ];




    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */



    /**
     * Revenue Service
     */
    public function service()
    {

        return $this->belongsTo(
            RevenueService::class,
            'service_id'
        );

    }





    /**
     * Sector
     */
    public function sector()
    {

        return $this->belongsTo(
            Sector::class,
            'sector_id'
        );

    }





    /**
     * Role
     */
    public function role()
    {

        return $this->belongsTo(
            Role::class,
            'role_id'
        );

    }





    /**
     * Created By User
     */
    public function creator()
    {

        return $this->belongsTo(
            User::class,
            'created_by'
        );

    }





    /**
     * Updated By User
     */
    public function updater()
    {

        return $this->belongsTo(
            User::class,
            'updated_by'
        );

    }





    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */



    /**
     * Active rules only
     */
    public function scopeActive($query)
    {

        return $query->where(
            'is_active',
            true
        );

    }




    /**
     * Check action permission
     */
    public function hasAction(
        string $action
    ): bool
    {

        return in_array(
            $action,
            $this->actions ?? []
        );

    }

}