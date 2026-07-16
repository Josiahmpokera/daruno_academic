<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    protected $fillable = [
        'school_id',
        'academic_year_id',
        'term_id',
        'classroom_id',
        'subject_id',
        'name',
        'type',
        'date',
        'max_score',
        'weight',
        'is_published',
        'is_locked',
    ];

    protected $casts = [
        'date' => 'date',
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_published' => 'boolean',
        'is_locked' => 'boolean',
    ];

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

    public function results(): HasMany
    {
        return $this->hasMany(AssessmentResult::class);
    }
}
