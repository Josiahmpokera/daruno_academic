<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'school_id', 'channel', 'key', 'subject', 'body',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
