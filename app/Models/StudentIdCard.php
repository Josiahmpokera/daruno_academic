<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentIdCard extends Model
{
    protected $fillable = [
        'school_id', 'student_id', 'card_number', 'template_key',
        'verification_token', 'barcode_value', 'qr_payload',
        'status', 'issued_on', 'expires_on', 'print_count',
        'last_printed_at', 'issued_by', 'meta',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'last_printed_at' => 'datetime',
        'print_count' => 'integer',
        'meta' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
