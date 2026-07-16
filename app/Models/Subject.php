<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'school_id',
        'subject_category_id',
        'name',
        'short_name',
        'code',
        'necta_code',
        'education_stage',
        'is_core',
        'is_compulsory',
        'is_active',
        'weekly_periods',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'is_compulsory' => 'boolean',
        'is_active' => 'boolean',
        'weekly_periods' => 'integer',
        'sort_order' => 'integer',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SubjectCategory::class, 'subject_category_id');
    }

    public function combinations(): BelongsToMany
    {
        return $this->belongsToMany(SubjectCombination::class, 'subject_combination_subject')
            ->withPivot(['is_principal', 'sort_order'])
            ->withTimestamps();
    }

    public function classroomSubjects(): HasMany
    {
        return $this->hasMany(ClassroomSubject::class);
    }

    public function teacherAllocations(): HasMany
    {
        return $this->hasMany(TeacherSubjectAllocation::class);
    }
}
