<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class AdministrativeUnit extends Model
{

    use HasUuids;


    protected $table = 'administrative_units';


    protected $keyType = 'string';


    public $incrementing = false;



    protected $fillable = [

        'name',

        'code',

        'parent_id',

        'level',

        'is_active',

        'created_by',

        'updated_by',

    ];



    protected $casts = [

        'is_active' => 'boolean',

    ];



    /*
    |--------------------------------------------------------------------------
    | Parent Administrative Unit
    |--------------------------------------------------------------------------
    |
    | SUBCITY belongs to CITY
    | WEREDA belongs to SUBCITY
    |
    */

    public function parent(): BelongsTo
    {

        return $this->belongsTo(
            AdministrativeUnit::class,
            'parent_id'
        );

    }



    /*
    |--------------------------------------------------------------------------
    | Children
    |--------------------------------------------------------------------------
    |
    | CITY has SUBCITY
    | SUBCITY has WEREDA
    |
    */

    public function children(): HasMany
    {

        return $this->hasMany(
            AdministrativeUnit::class,
            'parent_id'
        );

    }



    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeCity($query)
    {

        return $query->where(
            'level',
            'CITY'
        );

    }



    public function scopeSubcity($query)
    {

        return $query->where(
            'level',
            'SUBCITY'
        );

    }



    public function scopeWereda($query)
    {

        return $query->where(
            'level',
            'WEREDA'
        );

    }

    /**
     * Get the full lineage as a collection
     * Example: Wereda -> Subcity -> City
     */
    public function getLineageAttribute()
    {
        $lineage = collect([$this]);
        $parent = $this->parent;

        while ($parent) {
            $lineage->push($parent);
            $parent = $parent->parent;
        }

        return $lineage;
    }


}