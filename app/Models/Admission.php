<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admission extends Model
{
    protected $fillable = [
        'school_id',
        'application_number',
        'academic_year_id',
        'classroom_id',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'birth_certificate_no',
        'gender',
        'email',
        'phone',
        'nationality',
        'religion',
        'previous_school',
        'previous_class',
        'preferred_level',
        'address',
        'source',
        'notes',
        'rejection_reason',
        'status',
        'applied_at',
        'decided_at',
        'student_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'applied_at' => 'date',
        'decided_at' => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(AdmissionGuardian::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }
}
