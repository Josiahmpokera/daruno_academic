<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = [
        'school_id',
        'kind',
        'title',
        'body',
        'category',
        'audience',
        'classroom_ids',
        'priority',
        'channels',
        'status',
        'is_pinned',
        'show_on_board',
        'publish_at',
        'published_at',
        'expires_at',
        'recipients_count',
        'delivery_summary',
        'created_by',
        'published_by',
    ];

    protected $casts = [
        'classroom_ids' => 'array',
        'channels' => 'array',
        'delivery_summary' => 'array',
        'is_pinned' => 'boolean',
        'show_on_board' => 'boolean',
        'publish_at' => 'datetime',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
