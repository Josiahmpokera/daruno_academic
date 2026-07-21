<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryReservation extends Model
{
    protected $fillable = [
        'school_id', 'book_id', 'student_id', 'staff_id',
        'reserved_on', 'expires_on', 'status', 'notes',
    ];

    protected $casts = [
        'reserved_on' => 'date',
        'expires_on' => 'date',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'book_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
