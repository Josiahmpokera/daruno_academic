<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffContract extends Model
{
    protected $fillable = [
        'school_id', 'staff_id', 'contract_number', 'title', 'employment_type',
        'start_date', 'end_date', 'probation_end_date', 'basic_salary', 'currency',
        'status', 'terms', 'signed_at', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'probation_end_date' => 'date',
        'basic_salary' => 'decimal:2',
        'signed_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
