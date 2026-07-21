<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTransfer extends Model
{
    protected $fillable = [
        'school_id',
        'student_id',
        'direction',
        'other_school_name',
        'other_school_code',
        'transfer_date',
        'reason',
        'reference_number',
        'status',
        'notes',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'processed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
