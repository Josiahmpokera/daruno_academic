<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentThread extends Model
{
    protected $fillable = [
        'school_id',
        'guardian_id',
        'student_id',
        'subject',
        'status',
        'last_message_at',
        'last_message_preview',
        'last_direction',
        'unread_count',
        'created_by',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(StudentGuardian::class, 'guardian_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ParentMessage::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
