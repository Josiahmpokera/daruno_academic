<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Term extends Model
{
    protected $fillable = [
        'school_id',
        'academic_year_id',
        'name',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
