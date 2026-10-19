<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionDecision extends Model
{
    protected $fillable = [
        'school_id', 'promotion_run_id', 'student_id',
        'from_classroom_id', 'to_classroom_id',
        'from_enrollment_id', 'to_enrollment_id', 'promotion_rule_id',
        'outcome', 'average_percent', 'attendance_percent', 'reason',
    ];

    protected $casts = [
        'average_percent' => 'decimal:2',
        'attendance_percent' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class, 'promotion_run_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'from_classroom_id');
    }

    public function toClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'to_classroom_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PromotionRule::class, 'promotion_rule_id');
    }
}
