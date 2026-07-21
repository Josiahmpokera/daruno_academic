<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRoute extends Model
{
    protected $table = 'routes';

    protected $fillable = ['school_id', 'name', 'code'];

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('sequence');
    }

    public function studentRoutes(): HasMany
    {
        return $this->hasMany(StudentRoute::class, 'route_id');
    }
}
