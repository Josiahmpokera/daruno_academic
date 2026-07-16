<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomSubject extends Model
{
    protected $fillable = [
        'school_id',
        'classroom_id',
        'subject_id',
        'academic_year_id',
        'is_compulsory',
        'periods_per_week',
    ];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'periods_per_week' => 'integer',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
