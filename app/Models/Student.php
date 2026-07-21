<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'admission_number',
        'prems_number',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'birth_certificate_no',
        'place_of_birth',
        'district_of_birth',
        'region_of_birth',
        'gender',
        'nationality',
        'religion',
        'disability',
        'blood_group',
        'phone',
        'address_region',
        'address_district',
        'address_ward',
        'address_street',
        'previous_school',
        'previous_class',
        'boarding_status',
        'admission_date',
        'status',
        'notes',
        'alumni_year',
        'alumni_phone',
        'alumni_email',
        'alumni_occupation',
        'alumni_notes',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function healthRecord()
    {
        return $this->hasOne(StudentHealthRecord::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StudentTransfer::class);
    }

    public function graduation()
    {
        return $this->hasOne(StudentGraduation::class);
    }

    public function currentEnrollment()
    {
        return $this->hasOne(Enrollment::class)->ofMany(
            ['id' => 'max'],
            function ($query) {
                $query->where('status', 'active');
            }
        );
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
