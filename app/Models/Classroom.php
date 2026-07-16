<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'code',
        'education_level_id',
        'stream_id',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
