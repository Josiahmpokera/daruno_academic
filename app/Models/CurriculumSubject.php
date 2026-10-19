<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumSubject extends Model
{
    protected $fillable = [
        'curriculum_id', 'subject_id', 'is_compulsory', 'weekly_periods', 'sort_order',
    ];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'weekly_periods' => 'integer',
        'sort_order' => 'integer',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
