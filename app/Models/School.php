<?php

namespace App\Models;

use App\Support\EducationStages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'slug',
        'status',
        'registration_number',
        'emis_code',
        'school_type',
        'ownership',
        'education_level',
        'education_stages',
        'contact_email',
        'contact_phone',
        'address',
        'city',
        'region',
        'district',
        'country',
        'website',
        'motto',
        'vision',
        'mission',
        'logo_url',
        'working_days',
        'calendar_notes',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'working_days' => 'array',
        'education_stages' => 'array',
    ];

    /**
     * Stages this school operates (multi-select TAMISEMI).
     *
     * @return list<string>
     */
    public function offeredStages(): array
    {
        $stages = EducationStages::normalize($this->education_stages ?? []);

        // Legacy single education_level string → best-effort map
        if ($stages === [] && is_string($this->education_level) && $this->education_level !== '') {
            $legacy = strtolower($this->education_level);
            if (str_contains($legacy, 'pre')) {
                $stages[] = EducationStages::PRE_PRIMARY;
            }
            if (str_contains($legacy, 'primary') && ! str_contains($legacy, 'pre')) {
                $stages[] = EducationStages::PRIMARY;
            }
            if (str_contains($legacy, 'secondary') || str_contains($legacy, 'o-level') || str_contains($legacy, 'ordinary')) {
                $stages[] = EducationStages::ORDINARY_LEVEL;
            }
            if (str_contains($legacy, 'a-level') || str_contains($legacy, 'advanced')) {
                $stages[] = EducationStages::ADVANCED_LEVEL;
            }
            if (str_contains($legacy, 'combined') || str_contains($legacy, 'mixed')) {
                $stages = EducationStages::keys();
            }
            $stages = EducationStages::normalize($stages);
        }

        return $stages;
    }

    public function offersStage(string $stage): bool
    {
        return in_array($stage, $this->offeredStages(), true);
    }


    public function subscriptions()
    {
        return $this->hasMany(SchoolSubscription::class);
    }

    public function currentSubscription()
    {
        return $this->hasOne(SchoolSubscription::class)->latestOfMany();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

