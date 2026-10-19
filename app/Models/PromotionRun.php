<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionRun extends Model
{
    protected $fillable = [
        'school_id', 'from_academic_year_id', 'to_academic_year_id', 'from_classroom_id',
        'mode', 'status', 'title', 'run_at', 'created_by',
        'promoted_count', 'repeated_count', 'skipped_count', 'meta',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'promoted_count' => 'integer',
        'repeated_count' => 'integer',
        'skipped_count' => 'integer',
        'meta' => 'array',
    ];

    public function fromYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'from_academic_year_id');
    }

    public function toYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'to_academic_year_id');
    }

    public function fromClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'from_classroom_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PromotionDecision::class);
    }
}
