<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostelAllocation extends Model
{
    protected $fillable = [
        'school_id', 'student_id', 'hostel_id', 'room_id', 'bed_id',
        'check_in_on', 'check_out_on', 'status', 'fee_amount', 'notes',
    ];

    protected $casts = [
        'check_in_on' => 'date',
        'check_out_on' => 'date',
        'fee_amount' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'room_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(HostelBed::class, 'bed_id');
    }
}
