<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSubjectAllocation extends Model
{
    protected $fillable = [
        'school_id',
        'staff_id',
        'subject_id',
        'classroom_id',
        'academic_year_id',
        'term_id',
        'periods_per_week',
        'is_primary_teacher',
        'notes',
    ];

    protected $casts = [
        'periods_per_week' => 'integer',
        'is_primary_teacher' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
