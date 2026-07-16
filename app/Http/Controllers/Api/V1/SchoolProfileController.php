<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\EducationStages;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SchoolProfileController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public function show(Request $request)
    {
        try {
            $school = $this->school($request);

            return $this->success([
                'school' => $this->transform($school),
                'education_stage_catalog' => EducationStages::catalog(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function update(Request $request)
    {
        try {
            $school = $this->school($request);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'slug' => ['sometimes', 'nullable', 'string', 'max:100'],
                'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
                'emis_code' => ['sometimes', 'nullable', 'string', 'max:100'],
                'school_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'ownership' => ['sometimes', 'nullable', 'string', 'max:50'],
                'education_level' => ['sometimes', 'nullable', 'string', 'max:80'],
                'education_stages' => ['sometimes', 'array', 'min:1'],
                'education_stages.*' => ['string', Rule::in(EducationStages::keys())],
                'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'address' => ['sometimes', 'nullable', 'string', 'max:255'],
                'city' => ['sometimes', 'nullable', 'string', 'max:120'],
                'region' => ['sometimes', 'nullable', 'string', 'max:120'],
                'country' => ['sometimes', 'nullable', 'string', 'max:120'],
                'website' => ['sometimes', 'nullable', 'string', 'max:255'],
                'motto' => ['sometimes', 'nullable', 'string', 'max:255'],
                'vision' => ['sometimes', 'nullable', 'string'],
                'mission' => ['sometimes', 'nullable', 'string'],
                'logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
                'working_days' => ['sometimes', 'nullable', 'array'],
                'working_days.*' => ['string'],
                'calendar_notes' => ['sometimes', 'nullable', 'string'],
                'settings' => ['sometimes', 'nullable', 'array'],
            ]);

            if (isset($data['name']) && empty($data['slug']) && empty($school->slug)) {
                $data['slug'] = Str::slug($data['name']);
            }

            if (array_key_exists('settings', $data) && is_array($data['settings'])) {
                $data['settings'] = array_merge($school->settings ?? [], $data['settings']);
            }

            if (array_key_exists('education_stages', $data)) {
                $stages = EducationStages::normalize($data['education_stages']);
                if ($stages === []) {
                    return $this->error('Select at least one education level.', 422);
                }
                $data['education_stages'] = $stages;
                // Keep human-readable summary in legacy field
                $data['education_level'] = EducationStages::labelSummary($stages);
            }

            $school->fill($data);
            $school->save();

            return $this->success([
                'school' => $this->transform($school->fresh()),
                'education_stage_catalog' => EducationStages::catalog(),
            ], 'School profile updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    protected function transform(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'code' => $school->code,
            'slug' => $school->slug,
            'status' => $school->status,
            'registration_number' => $school->registration_number,
            'emis_code' => $school->emis_code,
            'school_type' => $school->school_type,
            'ownership' => $school->ownership,
            'education_level' => $school->education_level
                ?: EducationStages::labelSummary($school->offeredStages()),
            'education_stages' => $school->offeredStages(),
            'contact_email' => $school->contact_email,
            'contact_phone' => $school->contact_phone,
            'address' => $school->address,
            'city' => $school->city,
            'region' => $school->region,
            'country' => $school->country,
            'website' => $school->website,
            'motto' => $school->motto,
            'vision' => $school->vision,
            'mission' => $school->mission,
            'logo_url' => $school->logo_url,
            'working_days' => $school->working_days ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'calendar_notes' => $school->calendar_notes,
            'settings' => $school->settings,
            'created_at' => optional($school->created_at)?->toIso8601String(),
            'updated_at' => optional($school->updated_at)?->toIso8601String(),
        ];
    }
}
