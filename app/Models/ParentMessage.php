<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentMessage extends Model
{
    protected $fillable = [
        'school_id',
        'thread_id',
        'direction',
        'body',
        'channels',
        'delivery',
        'sender_user_id',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'delivery' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ParentThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
