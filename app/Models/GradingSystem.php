<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingSystem extends Model
{
    protected $fillable = [
        'school_id', 'name', 'code', 'education_stage', 'education_level_id',
        'scale_type', 'pass_mark', 'is_default', 'is_active', 'notes',
    ];

    protected $casts = [
        'pass_mark' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function bands(): HasMany
    {
        return $this->hasMany(GradingBand::class)->orderByDesc('min_percent')->orderBy('sort_order');
    }

    /**
     * @return array{grade: string, points: int, remark: string, min_percent: float}
     */
    public function gradeFromPercent(float $percent): array
    {
        $percent = max(0, min(100, $percent));
        $bands = $this->relationLoaded('bands')
            ? $this->bands
            : $this->bands()->get();

        foreach ($bands->sortByDesc('min_percent') as $band) {
            if ($percent >= (float) $band->min_percent) {
                return [
                    'grade' => $band->grade,
                    'points' => (int) $band->points,
                    'remark' => $band->remark ?? '',
                    'min_percent' => (float) $band->min_percent,
                ];
            }
        }

        $last = $bands->sortBy('min_percent')->first();

        return [
            'grade' => $last?->grade ?? 'F',
            'points' => (int) ($last?->points ?? 5),
            'remark' => $last?->remark ?? 'Fail',
            'min_percent' => (float) ($last?->min_percent ?? 0),
        ];
    }
}
