<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingBand extends Model
{
    protected $fillable = [
        'grading_system_id', 'grade', 'min_percent', 'max_percent',
        'points', 'remark', 'sort_order',
    ];

    protected $casts = [
        'min_percent' => 'decimal:2',
        'max_percent' => 'decimal:2',
        'points' => 'integer',
        'sort_order' => 'integer',
    ];

    public function gradingSystem(): BelongsTo
    {
        return $this->belongsTo(GradingSystem::class);
    }
}
