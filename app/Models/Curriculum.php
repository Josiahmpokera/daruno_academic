<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $fillable = [
        'school_id', 'name', 'code', 'education_stage', 'education_level_id',
        'version', 'authority', 'description', 'is_active', 'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function curriculumSubjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class)->orderBy('sort_order');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'curriculum_subjects')
            ->withPivot(['is_compulsory', 'weekly_periods', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(CurriculumTopic::class)->orderBy('sort_order')->orderBy('id');
    }
}
