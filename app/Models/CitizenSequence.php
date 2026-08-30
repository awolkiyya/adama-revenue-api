<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CitizenSequence extends Model
{
    protected $fillable = [
        'year',
        'last_number',
    ];
}