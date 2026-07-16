<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Support\EducationStages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class StudentController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public function index(Request $request)
    {
        try {
            $school = $this->school($request);
            $schoolId = (int) $school->id;
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString();
            $classroomId = $request->integer('classroom_id') ?: null;
            $offered = $school->offeredStages();

            $query = Student::query()
                ->where('school_id', $schoolId)
                ->with(['currentEnrollment.classroom.educationLevel', 'guardians'])
                ->orderBy('last_name')
                ->orderBy('first_name');

            if ($q !== '') {
                $query->where(function ($builder) use ($q) {
                    $builder->where('first_name', 'like', "%{$q}%")
                        ->orWhere('middle_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('admission_number', 'like', "%{$q}%")
                        ->orWhere('prems_number', 'like', "%{$q}%");
                });
            }

            if ($status !== '') {
                $query->where('status', $status);
            }

            if ($classroomId) {
                $query->whereHas('enrollments', function ($builder) use ($classroomId) {
                    $builder->where('classroom_id', $classroomId)->where('status', 'active');
                });
            }

            // Only learners enrolled in classes whose level matches school education_stages
            if ($offered !== []) {
                $allowedLevelIds = EducationLevel::query()
                    ->where('school_id', $schoolId)
                    ->where('is_active', true)
                    ->get()
                    ->filter(function (EducationLevel $level) use ($offered) {
                        $stage = EducationStages::fromLevelCodeOrName($level->code, $level->name);

                        return $stage && in_array($stage, $offered, true);
                    })
                    ->pluck('id')
                    ->all();

                $query->where(function ($builder) use ($allowedLevelIds) {
                    $builder->whereHas('enrollments', function ($enr) use ($allowedLevelIds) {
                        $enr->where('status', 'active')
                            ->whereHas('classroom', function ($cls) use ($allowedLevelIds) {
                                if ($allowedLevelIds === []) {
                                    $cls->whereRaw('1 = 0');
                                } else {
                                    $cls->whereIn('education_level_id', $allowedLevelIds);
                                }
                            });
                    });
                });
            }

            $perPage = min(100, max(1, $request->integer('per_page', 25)));
            $page = $query->paginate($perPage);

            $items = collect($page->items())->map(fn (Student $s) => $this->transform($s));

            return $this->success([
                'students' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'school_education_stages' => $offered,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:120'],
                'middle_name' => ['nullable', 'string', 'max:120'],
                'last_name' => ['required', 'string', 'max:120'],
                'admission_number' => ['nullable', 'string', 'max:50'],
                'prems_number' => ['nullable', 'string', 'max:50'],
                'date_of_birth' => ['nullable', 'date'],
                'birth_certificate_no' => ['nullable', 'string', 'max:80'],
                'place_of_birth' => ['nullable', 'string', 'max:255'],
                'district_of_birth' => ['nullable', 'string', 'max:120'],
                'region_of_birth' => ['nullable', 'string', 'max:120'],
                'gender' => ['nullable', 'string', 'max:20'],
                'nationality' => ['nullable', 'string', 'max:80'],
                'religion' => ['nullable', 'string', 'max:80'],
                'disability' => ['nullable', 'string', 'max:120'],
                'blood_group' => ['nullable', 'string', 'max:10'],
                'phone' => ['nullable', 'string', 'max:40'],
                'address_region' => ['nullable', 'string', 'max:120'],
                'address_district' => ['nullable', 'string', 'max:120'],
                'address_ward' => ['nullable', 'string', 'max:120'],
                'address_street' => ['nullable', 'string', 'max:255'],
                'previous_school' => ['nullable', 'string', 'max:255'],
                'previous_class' => ['nullable', 'string', 'max:80'],
                'boarding_status' => ['nullable', 'in:day,boarding,hostel'],
                'admission_date' => ['nullable', 'date'],
                'status' => ['nullable', 'in:active,transferred,graduated,inactive'],
                'notes' => ['nullable', 'string'],
                'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
                'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
                'term_id' => ['nullable', 'integer', 'exists:terms,id'],
                'guardians' => ['nullable', 'array'],
                'guardians.*.relationship' => ['required_with:guardians', 'string', 'max:40'],
                'guardians.*.full_name' => ['required_with:guardians', 'string', 'max:255'],
                'guardians.*.phone' => ['nullable', 'string', 'max:40'],
                'guardians.*.email' => ['nullable', 'email', 'max:255'],
                'guardians.*.occupation' => ['nullable', 'string', 'max:120'],
                'guardians.*.nida_number' => ['nullable', 'string', 'max:50'],
                'guardians.*.address' => ['nullable', 'string', 'max:255'],
                'guardians.*.is_primary' => ['nullable', 'boolean'],
            ]);

            if (! empty($data['classroom_id'])) {
                $ok = Classroom::query()
                    ->where('school_id', $schoolId)
                    ->where('id', $data['classroom_id'])
                    ->exists();
                if (! $ok) {
                    return $this->error('Class does not belong to this school.', 422);
                }
            }

            $student = DB::transaction(function () use ($schoolId, $data) {
                $admission = $data['admission_number'] ?? $this->nextAdmissionNumber($schoolId);

                $student = Student::query()->create([
                    'school_id' => $schoolId,
                    'admission_number' => $admission,
                    'prems_number' => $data['prems_number'] ?? null,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'birth_certificate_no' => $data['birth_certificate_no'] ?? null,
                    'place_of_birth' => $data['place_of_birth'] ?? null,
                    'district_of_birth' => $data['district_of_birth'] ?? null,
                    'region_of_birth' => $data['region_of_birth'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'nationality' => $data['nationality'] ?? 'Tanzanian',
                    'religion' => $data['religion'] ?? null,
                    'disability' => $data['disability'] ?? null,
                    'blood_group' => $data['blood_group'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'address_region' => $data['address_region'] ?? null,
                    'address_district' => $data['address_district'] ?? null,
                    'address_ward' => $data['address_ward'] ?? null,
                    'address_street' => $data['address_street'] ?? null,
                    'previous_school' => $data['previous_school'] ?? null,
                    'previous_class' => $data['previous_class'] ?? null,
                    'boarding_status' => $data['boarding_status'] ?? 'day',
                    'admission_date' => $data['admission_date'] ?? now()->toDateString(),
                    'status' => $data['status'] ?? 'active',
                    'notes' => $data['notes'] ?? null,
                ]);

                if (! empty($data['classroom_id']) && ! empty($data['academic_year_id'])) {
                    Enrollment::query()->create([
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'classroom_id' => $data['classroom_id'],
                        'academic_year_id' => $data['academic_year_id'],
                        'term_id' => $data['term_id'] ?? null,
                        'enrolled_at' => $data['admission_date'] ?? now()->toDateString(),
                        'status' => 'active',
                    ]);
                }

                foreach ($data['guardians'] ?? [] as $g) {
                    if (empty($g['full_name'])) {
                        continue;
                    }
                    StudentGuardian::query()->create([
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'relationship' => $g['relationship'] ?? 'guardian',
                        'full_name' => $g['full_name'],
                        'phone' => $g['phone'] ?? null,
                        'email' => $g['email'] ?? null,
                        'occupation' => $g['occupation'] ?? null,
                        'nida_number' => $g['nida_number'] ?? null,
                        'address' => $g['address'] ?? null,
                        'is_primary' => (bool) ($g['is_primary'] ?? false),
                    ]);
                }

                return $student->load(['currentEnrollment.classroom', 'guardians']);
            });

            return $this->success(['student' => $this->transform($student, true)], 'Student registered', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $student = Student::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'currentEnrollment.classroom',
                    'enrollments.classroom',
                    'enrollments.academicYear',
                    'guardians',
                ])
                ->findOrFail($id);

            return $this->success(['student' => $this->transform($student, true)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /**
     * Headcount of active learners by education level and sex (boys / girls).
     * Rows are limited to stages the school operates (education_stages).
     */
    public function headcountByLevel(Request $request)
    {
        try {
            $school = $this->school($request);
            $schoolId = (int) $school->id;
            $offered = $school->offeredStages();

            // Active enrollments joined to student gender + classroom level
            $rows = Enrollment::query()
                ->where('enrollments.status', 'active')
                ->whereHas('student', fn ($q) => $q->where('school_id', $schoolId)->where('status', 'active'))
                ->join('students', 'students.id', '=', 'enrollments.student_id')
                ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
                ->leftJoin('education_levels', 'education_levels.id', '=', 'classrooms.education_level_id')
                ->where('classrooms.school_id', $schoolId)
                ->select([
                    'education_levels.id as level_id',
                    'education_levels.name as level_name',
                    'education_levels.code as level_code',
                    'education_levels.sort_order as sort_order',
                    'students.gender as gender',
                    DB::raw('COUNT(DISTINCT students.id) as total'),
                ])
                ->groupBy(
                    'education_levels.id',
                    'education_levels.name',
                    'education_levels.code',
                    'education_levels.sort_order',
                    'students.gender'
                )
                ->get();

            // Seed buckets from school education levels (filtered by offered stages)
            $levels = EducationLevel::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $buckets = [];
            foreach ($levels as $level) {
                $stage = $this->mapLevelToStage($level->code, $level->name);
                if ($offered !== [] && $stage && ! in_array($stage, $offered, true)) {
                    continue;
                }
                $key = $level->id ? 'L'.$level->id : 'unknown';
                $buckets[$key] = [
                    'education_level_id' => $level->id,
                    'name' => $level->name,
                    'code' => $level->code,
                    'stage' => $stage,
                    'boys' => 0,
                    'girls' => 0,
                    'other' => 0,
                    'total' => 0,
                    'sort_order' => (int) $level->sort_order,
                ];
            }

            $hasUnassigned = false;
            foreach ($rows as $row) {
                $levelId = $row->level_id;
                $stage = $this->mapLevelToStage($row->level_code, $row->level_name);
                $key = $levelId ? 'L'.$levelId : 'unassigned';

                if ($key === 'unassigned') {
                    $hasUnassigned = true;
                    if (! isset($buckets['unassigned'])) {
                        $buckets['unassigned'] = [
                            'education_level_id' => null,
                            'name' => 'Unassigned class',
                            'code' => null,
                            'stage' => null,
                            'boys' => 0,
                            'girls' => 0,
                            'other' => 0,
                            'total' => 0,
                            'sort_order' => 9999,
                        ];
                    }
                } elseif (! isset($buckets[$key])) {
                    // Level inactive or filtered out — still show if it has learners
                    if ($offered !== [] && $stage && ! in_array($stage, $offered, true)) {
                        continue;
                    }
                    $buckets[$key] = [
                        'education_level_id' => $levelId,
                        'name' => $row->level_name ?: 'Unknown',
                        'code' => $row->level_code,
                        'stage' => $stage,
                        'boys' => 0,
                        'girls' => 0,
                        'other' => 0,
                        'total' => 0,
                        'sort_order' => (int) ($row->sort_order ?? 500),
                    ];
                }

                $count = (int) $row->total;
                $g = strtolower(trim((string) $row->gender));
                if (in_array($g, ['male', 'm', 'boy', 'boys'], true)) {
                    $buckets[$key]['boys'] += $count;
                } elseif (in_array($g, ['female', 'f', 'girl', 'girls'], true)) {
                    $buckets[$key]['girls'] += $count;
                } else {
                    $buckets[$key]['other'] += $count;
                }
                $buckets[$key]['total'] += $count;
            }

            // If school offers a stage but has no education_level row, still show empty row
            if ($offered !== []) {
                $catalog = collect(EducationStages::catalog())->keyBy('value');
                foreach ($offered as $stageKey) {
                    $exists = collect($buckets)->contains(fn ($b) => ($b['stage'] ?? null) === $stageKey);
                    if (! $exists && isset($catalog[$stageKey])) {
                        $buckets['stage_'.$stageKey] = [
                            'education_level_id' => null,
                            'name' => $catalog[$stageKey]['label'],
                            'code' => strtoupper(substr($stageKey, 0, 2)),
                            'stage' => $stageKey,
                            'boys' => 0,
                            'girls' => 0,
                            'other' => 0,
                            'total' => 0,
                            'sort_order' => array_search($stageKey, EducationStages::keys(), true) * 10,
                        ];
                    }
                }
            }

            $list = array_values($buckets);
            usort($list, fn ($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['name'], $b['name']));

            $totals = [
                'boys' => array_sum(array_column($list, 'boys')),
                'girls' => array_sum(array_column($list, 'girls')),
                'other' => array_sum(array_column($list, 'other')),
                'total' => array_sum(array_column($list, 'total')),
            ];

            return $this->success([
                'by_level' => $list,
                'totals' => $totals,
                'school_education_stages' => $offered,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Map education_levels code/name → canonical stage key.
     */
    protected function mapLevelToStage(?string $code, ?string $name): ?string
    {
        return EducationStages::fromLevelCodeOrName($code, $name);
    }

    public function update(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $student = Student::query()->where('school_id', $schoolId)->findOrFail($id);

            $data = $request->validate([
                'first_name' => ['sometimes', 'string', 'max:120'],
                'middle_name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'last_name' => ['sometimes', 'string', 'max:120'],
                'admission_number' => ['sometimes', 'nullable', 'string', 'max:50'],
                'prems_number' => ['sometimes', 'nullable', 'string', 'max:50'],
                'date_of_birth' => ['sometimes', 'nullable', 'date'],
                'birth_certificate_no' => ['sometimes', 'nullable', 'string', 'max:80'],
                'place_of_birth' => ['sometimes', 'nullable', 'string', 'max:255'],
                'district_of_birth' => ['sometimes', 'nullable', 'string', 'max:120'],
                'region_of_birth' => ['sometimes', 'nullable', 'string', 'max:120'],
                'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
                'nationality' => ['sometimes', 'nullable', 'string', 'max:80'],
                'religion' => ['sometimes', 'nullable', 'string', 'max:80'],
                'disability' => ['sometimes', 'nullable', 'string', 'max:120'],
                'blood_group' => ['sometimes', 'nullable', 'string', 'max:10'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'address_region' => ['sometimes', 'nullable', 'string', 'max:120'],
                'address_district' => ['sometimes', 'nullable', 'string', 'max:120'],
                'address_ward' => ['sometimes', 'nullable', 'string', 'max:120'],
                'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
                'previous_school' => ['sometimes', 'nullable', 'string', 'max:255'],
                'previous_class' => ['sometimes', 'nullable', 'string', 'max:80'],
                'boarding_status' => ['sometimes', 'nullable', 'in:day,boarding,hostel'],
                'admission_date' => ['sometimes', 'nullable', 'date'],
                'status' => ['sometimes', 'in:active,transferred,graduated,inactive'],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            $student->fill($data);
            $student->save();

            return $this->success([
                'student' => $this->transform(
                    $student->fresh(['currentEnrollment.classroom', 'guardians'])
                ),
            ], 'Student updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $student = Student::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $student->delete();

            return $this->success(['id' => $id], 'Student deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    protected function nextAdmissionNumber(int $schoolId): string
    {
        $year = now()->format('Y');
        $count = Student::withTrashed()->where('school_id', $schoolId)->count() + 1;

        return sprintf('ADM-%s-%04d', $year, $count);
    }

    protected function transform(Student $student, bool $detailed = false): array
    {
        $enrollment = $student->relationLoaded('currentEnrollment')
            ? $student->currentEnrollment
            : $student->currentEnrollment()->with('classroom')->first();

        $base = [
            'id' => $student->id,
            'admission_number' => $student->admission_number,
            'prems_number' => $student->prems_number,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'last_name' => $student->last_name,
            'full_name' => $student->full_name,
            'date_of_birth' => optional($student->date_of_birth)?->toDateString(),
            'birth_certificate_no' => $student->birth_certificate_no,
            'place_of_birth' => $student->place_of_birth,
            'district_of_birth' => $student->district_of_birth,
            'region_of_birth' => $student->region_of_birth,
            'gender' => $student->gender,
            'nationality' => $student->nationality,
            'religion' => $student->religion,
            'disability' => $student->disability,
            'blood_group' => $student->blood_group,
            'phone' => $student->phone,
            'address_region' => $student->address_region,
            'address_district' => $student->address_district,
            'address_ward' => $student->address_ward,
            'address_street' => $student->address_street,
            'previous_school' => $student->previous_school,
            'previous_class' => $student->previous_class,
            'boarding_status' => $student->boarding_status,
            'admission_date' => optional($student->admission_date)?->toDateString(),
            'status' => $student->status,
            'notes' => $student->notes,
            'classroom' => $enrollment?->classroom ? [
                'id' => $enrollment->classroom->id,
                'name' => $enrollment->classroom->name,
                'code' => $enrollment->classroom->code,
            ] : null,
            'guardians' => $student->relationLoaded('guardians')
                ? $student->guardians->map(fn ($g) => [
                    'id' => $g->id,
                    'relationship' => $g->relationship,
                    'full_name' => $g->full_name,
                    'phone' => $g->phone,
                    'email' => $g->email,
                    'occupation' => $g->occupation,
                    'nida_number' => $g->nida_number,
                    'address' => $g->address,
                    'is_primary' => (bool) $g->is_primary,
                ])->values()
                : [],
            'created_at' => optional($student->created_at)?->toIso8601String(),
        ];

        if ($detailed) {
            $base['enrollments'] = $student->relationLoaded('enrollments')
                ? $student->enrollments->map(fn ($e) => [
                    'id' => $e->id,
                    'classroom' => $e->classroom?->name,
                    'academic_year' => $e->academicYear?->name,
                    'status' => $e->status,
                    'enrolled_at' => optional($e->enrolled_at)?->toDateString(),
                ])
                : [];
        }

        return $base;
    }
}
