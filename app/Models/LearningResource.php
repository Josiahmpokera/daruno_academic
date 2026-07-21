<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningResource extends Model
{
    protected $fillable = [
        'school_id', 'type', 'title', 'description',
        'subject_id', 'classroom_id', 'education_level_id',
        'academic_year_id', 'term_id',
        'file_path', 'external_url', 'mime_type', 'file_size',
        'exam_body', 'exam_year', 'paper_code', 'class_level', 'language',
        'status', 'is_featured', 'view_count', 'download_count', 'created_by',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'exam_year' => 'integer',
        'view_count' => 'integer',
        'download_count' => 'integer',
        'file_size' => 'integer',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
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
