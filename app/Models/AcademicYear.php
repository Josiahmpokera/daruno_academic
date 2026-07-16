<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'starts_at',
        'ends_at',
        'is_current',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'is_current' => 'boolean',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
