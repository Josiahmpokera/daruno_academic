<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolIdCardSetting extends Model
{
    protected $fillable = [
        'school_id', 'default_template', 'card_prefix', 'validity_years',
        'front_footer', 'back_note', 'show_photo_placeholder',
        'show_blood_group', 'show_guardian', 'settings',
    ];

    protected $casts = [
        'validity_years' => 'integer',
        'show_photo_placeholder' => 'boolean',
        'show_blood_group' => 'boolean',
        'show_guardian' => 'boolean',
        'settings' => 'array',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
