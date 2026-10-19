<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'school_id',
        'employee_code',
        'first_name',
        'middle_name',
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
        'terminated_at',
        'status',
        'onboarding_status',
        'national_id',
        'date_of_birth',
        'gender',
        'phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'bank_name',
        'bank_account',
        'bank_branch',
        'notes',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'contract_end_date' => 'date',
        'terminated_at' => 'date',
        'date_of_birth' => 'date',
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

    public function contracts(): HasMany
    {
        return $this->hasMany(StaffContract::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StaffDocument::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(StaffLeaveRequest::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(StaffAttendanceRecord::class);
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
        if ($name !== '') {
            return $name;
        }

        return $this->user?->name ?? $this->employee_code ?? 'Staff #'.$this->id;
    }
}
