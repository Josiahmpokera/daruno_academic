<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SubjectCombination extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'code',
        'education_stage',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'subject_combination_subject')
            ->withPivot(['is_principal', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
