<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructure extends Model
{
    protected $fillable = [
        'school_id',
        'academic_year_id',
        'term_id',
        'classroom_id',
        'fee_category_id',
        'amount',
        'currency',
        'due_date',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
