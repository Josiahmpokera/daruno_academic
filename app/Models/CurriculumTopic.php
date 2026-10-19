<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumTopic extends Model
{
    protected $fillable = [
        'curriculum_id', 'subject_id', 'title', 'code', 'term_number',
        'week_from', 'week_to', 'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'term_number' => 'integer',
        'week_from' => 'integer',
        'week_to' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
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
