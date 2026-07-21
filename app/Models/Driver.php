<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = [
        'school_id', 'name', 'phone', 'license_number', 'status',
    ];
}
