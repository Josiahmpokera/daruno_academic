<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRule extends Model
{
    protected $fillable = [
        'school_id', 'name', 'education_level_id', 'from_classroom_id', 'to_classroom_id',
        'min_average_percent', 'min_attendance_percent', 'priority', 'is_active', 'notes',
    ];

    protected $casts = [
        'min_average_percent' => 'decimal:2',
        'min_attendance_percent' => 'decimal:2',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function fromClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'from_classroom_id');
    }

    public function toClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'to_classroom_id');
    }
}
