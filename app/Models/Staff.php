<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'school_id',
        'employee_code',
        'first_name',
        'last_name',
        'email',
        'department_id',
        'staff_position_id',
        'user_id',
        'salary_level_id',
        'position',
        'employment_type',
        'hire_date',
        'contract_end_date',
        'status',
        'national_id',
        'phone',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'contract_end_date' => 'date',
    ];

    protected $appends = ['full_name'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function staffPosition(): BelongsTo
    {
        return $this->belongsTo(StaffPosition::class, 'staff_position_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $this->user?->name ?? $this->employee_code ?? 'Staff #'.$this->id;
    }
}
