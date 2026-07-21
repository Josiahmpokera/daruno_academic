<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionGuardian;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AdmissionController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Public (website join form) ────────────────────────────────

    public function publicSchoolInfo(string $code)
    {
        try {
            $school = School::query()
                ->where(function ($q) use ($code) {
                    $q->where('code', $code)->orWhere('slug', $code);
                })
                ->first();

            if (! $school) {
                return $this->error('School not found. Check the school code.', 404);
            }

            if (in_array($school->status, ['suspended', 'rejected'], true)) {
                return $this->error('This school is not accepting applications right now.', 403);
            }

            return $this->success([
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'code' => $school->code,
                    'slug' => $school->slug,
                    'region' => $school->region,
                    'district' => $school->district,
                    'country' => $school->country,
                    'education_level' => $school->education_level,
                    'education_stages' => $school->education_stages,
                    'logo_url' => $school->logo_url,
                    'motto' => $school->motto,
                    'contact_email' => $school->contact_email,
                    'contact_phone' => $school->contact_phone,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function publicApply(Request $request, string $code)
    {
        try {
            $school = School::query()
                ->where(function ($q) use ($code) {
                    $q->where('code', $code)->orWhere('slug', $code);
                })
                ->first();

            if (! $school) {
                return $this->error('School not found. Check the school code.', 404);
            }

            if (in_array($school->status, ['suspended', 'rejected'], true)) {
                return $this->error('This school is not accepting applications right now.', 403);
            }

            $data = $this->validateApplication($request);

            $admission = $this->createAdmission($school->id, $data, 'public_web');

            return $this->success([
                'application' => [
                    'id' => $admission->id,
                    'application_number' => $admission->application_number,
                    'status' => $admission->status,
                    'full_name' => $admission->full_name,
                    'school' => [
                        'name' => $school->name,
                        'code' => $school->code,
                    ],
                    'applied_at' => optional($admission->applied_at)?->toDateString(),
                ],
            ], 'Application submitted successfully. Keep your application number for reference.', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── School portal ─────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString();

            $query = Admission::query()
                ->where('school_id', $schoolId)
                ->with(['guardians', 'classroom:id,name', 'academicYear:id,name', 'student:id,admission_number'])
                ->orderByDesc('id');

            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('middle_name', 'like', "%{$q}%")
                        ->orWhere('application_number', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $summary = [
                'pending' => Admission::query()->where('school_id', $schoolId)->where('status', 'pending')->count(),
                'approved' => Admission::query()->where('school_id', $schoolId)->where('status', 'approved')->count(),
                'rejected' => Admission::query()->where('school_id', $schoolId)->where('status', 'rejected')->count(),
                'enrolled' => Admission::query()->where('school_id', $schoolId)->where('status', 'enrolled')->count(),
                'total' => Admission::query()->where('school_id', $schoolId)->count(),
            ];

            return $this->success([
                'applications' => collect($page->items())->map(fn (Admission $a) => $this->transform($a)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $this->validateApplication($request);
            $admission = $this->createAdmission($schoolId, $data, 'school_portal');

            return $this->success([
                'application' => $this->transform($admission->load(['guardians', 'classroom', 'academicYear'])),
            ], 'Application created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $admission = Admission::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['guardians', 'classroom', 'academicYear', 'student'])
                ->findOrFail($id);

            return $this->success(['application' => $this->transform($admission, true)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $admission = Admission::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $data = $request->validate([
                'first_name' => ['sometimes', 'string', 'max:120'],
                'middle_name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'last_name' => ['sometimes', 'string', 'max:120'],
                'date_of_birth' => ['sometimes', 'nullable', 'date'],
                'birth_certificate_no' => ['sometimes', 'nullable', 'string', 'max:80'],
                'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'nationality' => ['sometimes', 'nullable', 'string', 'max:80'],
                'religion' => ['sometimes', 'nullable', 'string', 'max:80'],
                'previous_school' => ['sometimes', 'nullable', 'string', 'max:255'],
                'previous_class' => ['sometimes', 'nullable', 'string', 'max:80'],
                'preferred_level' => ['sometimes', 'nullable', 'string', 'max:80'],
                'address' => ['sometimes', 'nullable', 'string', 'max:255'],
                'academic_year_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_years,id'],
                'classroom_id' => ['sometimes', 'nullable', 'integer', 'exists:classrooms,id'],
                'notes' => ['sometimes', 'nullable', 'string'],
                'status' => ['sometimes', 'in:pending,approved,rejected,enrolled,withdrawn'],
                'rejection_reason' => ['sometimes', 'nullable', 'string'],
            ]);

            $admission->fill($data);
            if (isset($data['status']) && in_array($data['status'], ['approved', 'rejected', 'enrolled'], true)) {
                $admission->decided_at = $admission->decided_at ?? now()->toDateString();
            }
            $admission->save();

            return $this->success([
                'application' => $this->transform($admission->fresh()->load(['guardians', 'classroom', 'academicYear', 'student'])),
            ], 'Application updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function approve(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $admission = Admission::query()
                ->where('school_id', $schoolId)
                ->with('guardians')
                ->findOrFail($id);

            if ($admission->status === 'enrolled' && $admission->student_id) {
                return $this->error('Application already enrolled as a student.', 422);
            }

            $data = $request->validate([
                'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
                'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
                'admission_number' => ['nullable', 'string', 'max:50'],
            ]);

            $classroomId = $data['classroom_id'] ?? $admission->classroom_id;
            $yearId = $data['academic_year_id'] ?? $admission->academic_year_id
                ?? AcademicYear::query()->where('school_id', $schoolId)->where('is_current', true)->value('id')
                ?? AcademicYear::query()->where('school_id', $schoolId)->orderByDesc('id')->value('id');

            if ($classroomId) {
                $ok = Classroom::query()->where('school_id', $schoolId)->where('id', $classroomId)->exists();
                if (! $ok) {
                    return $this->error('Class does not belong to this school.', 422);
                }
            }

            $result = DB::transaction(function () use ($schoolId, $admission, $data, $classroomId, $yearId) {
                $admissionNumber = $data['admission_number'] ?? $this->nextAdmissionNumber($schoolId);

                $student = Student::query()->create([
                    'school_id' => $schoolId,
                    'admission_number' => $admissionNumber,
                    'first_name' => $admission->first_name,
                    'middle_name' => $admission->middle_name,
                    'last_name' => $admission->last_name,
                    'date_of_birth' => $admission->date_of_birth,
                    'birth_certificate_no' => $admission->birth_certificate_no,
                    'gender' => $admission->gender,
                    'nationality' => $admission->nationality ?? 'Tanzanian',
                    'religion' => $admission->religion,
                    'phone' => $admission->phone,
                    'previous_school' => $admission->previous_school,
                    'previous_class' => $admission->previous_class,
                    'admission_date' => now()->toDateString(),
                    'status' => 'active',
                    'notes' => $admission->notes,
                ]);

                foreach ($admission->guardians as $g) {
                    StudentGuardian::query()->create([
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'relationship' => $g->relationship ?: 'guardian',
                        'full_name' => $g->name,
                        'phone' => $g->phone,
                        'email' => $g->email,
                        'is_primary' => true,
                    ]);
                }

                if ($classroomId && $yearId) {
                    Enrollment::query()->create([
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'classroom_id' => $classroomId,
                        'academic_year_id' => $yearId,
                        'enrolled_at' => now()->toDateString(),
                        'status' => 'active',
                    ]);
                }

                $admission->status = 'enrolled';
                $admission->student_id = $student->id;
                $admission->classroom_id = $classroomId;
                $admission->academic_year_id = $yearId;
                $admission->decided_at = now()->toDateString();
                $admission->save();

                return $student;
            });

            return $this->success([
                'application' => $this->transform($admission->fresh()->load(['guardians', 'classroom', 'academicYear', 'student'])),
                'student' => [
                    'id' => $result->id,
                    'admission_number' => $result->admission_number,
                    'full_name' => $result->full_name,
                ],
            ], 'Application approved and student enrolled');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reject(Request $request, int $id)
    {
        try {
            $admission = Admission::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($admission->status === 'enrolled') {
                return $this->error('Cannot reject an already enrolled application.', 422);
            }

            $data = $request->validate([
                'rejection_reason' => ['nullable', 'string', 'max:1000'],
            ]);

            $admission->status = 'rejected';
            $admission->rejection_reason = $data['rejection_reason'] ?? null;
            $admission->decided_at = now()->toDateString();
            $admission->save();

            return $this->success([
                'application' => $this->transform($admission->fresh()->load(['guardians'])),
            ], 'Application rejected');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    protected function validateApplication(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date'],
            'birth_certificate_no' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'religion' => ['nullable', 'string', 'max:80'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'previous_class' => ['nullable', 'string', 'max:80'],
            'preferred_level' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'academic_year_id' => ['nullable', 'integer'],
            'classroom_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relationship' => ['nullable', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardians' => ['nullable', 'array'],
            'guardians.*.name' => ['required_with:guardians', 'string', 'max:255'],
            'guardians.*.relationship' => ['nullable', 'string', 'max:40'],
            'guardians.*.phone' => ['nullable', 'string', 'max:40'],
            'guardians.*.email' => ['nullable', 'email', 'max:255'],
        ]);
    }

    protected function createAdmission(int $schoolId, array $data, string $source): Admission
    {
        return DB::transaction(function () use ($schoolId, $data, $source) {
            $appNo = $this->nextApplicationNumber($schoolId);

            $admission = Admission::query()->create([
                'school_id' => $schoolId,
                'application_number' => $appNo,
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'classroom_id' => $data['classroom_id'] ?? null,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'birth_certificate_no' => $data['birth_certificate_no'] ?? null,
                'gender' => $data['gender'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'nationality' => $data['nationality'] ?? 'Tanzanian',
                'religion' => $data['religion'] ?? null,
                'previous_school' => $data['previous_school'] ?? null,
                'previous_class' => $data['previous_class'] ?? null,
                'preferred_level' => $data['preferred_level'] ?? null,
                'address' => $data['address'] ?? null,
                'source' => $source,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'applied_at' => now()->toDateString(),
            ]);

            $guardians = $data['guardians'] ?? [];
            if ($guardians === [] && ! empty($data['guardian_name'])) {
                $guardians[] = [
                    'name' => $data['guardian_name'],
                    'relationship' => $data['guardian_relationship'] ?? 'guardian',
                    'phone' => $data['guardian_phone'] ?? null,
                    'email' => $data['guardian_email'] ?? null,
                ];
            }

            foreach ($guardians as $g) {
                if (empty($g['name'])) {
                    continue;
                }
                AdmissionGuardian::query()->create([
                    'admission_id' => $admission->id,
                    'name' => $g['name'],
                    'relationship' => $g['relationship'] ?? 'guardian',
                    'phone' => $g['phone'] ?? null,
                    'email' => $g['email'] ?? null,
                ]);
            }

            return $admission->load('guardians');
        });
    }

    protected function nextApplicationNumber(int $schoolId): string
    {
        $year = now()->format('Y');
        $count = Admission::query()->where('school_id', $schoolId)->count() + 1;

        return sprintf('APP-%s-%04d', $year, $count);
    }

    protected function nextAdmissionNumber(int $schoolId): string
    {
        $year = now()->format('Y');
        $count = Student::withTrashed()->where('school_id', $schoolId)->count() + 1;

        return sprintf('ADM-%s-%04d', $year, $count);
    }

    protected function transform(Admission $a, bool $detailed = false): array
    {
        return [
            'id' => $a->id,
            'application_number' => $a->application_number,
            'first_name' => $a->first_name,
            'middle_name' => $a->middle_name,
            'last_name' => $a->last_name,
            'full_name' => $a->full_name,
            'date_of_birth' => optional($a->date_of_birth)?->toDateString(),
            'birth_certificate_no' => $a->birth_certificate_no,
            'gender' => $a->gender,
            'email' => $a->email,
            'phone' => $a->phone,
            'nationality' => $a->nationality,
            'religion' => $a->religion,
            'previous_school' => $a->previous_school,
            'previous_class' => $a->previous_class,
            'preferred_level' => $a->preferred_level,
            'address' => $a->address,
            'source' => $a->source,
            'notes' => $a->notes,
            'rejection_reason' => $a->rejection_reason,
            'status' => $a->status,
            'applied_at' => optional($a->applied_at)?->toDateString(),
            'decided_at' => optional($a->decided_at)?->toDateString(),
            'student_id' => $a->student_id,
            'academic_year' => $a->relationLoaded('academicYear') && $a->academicYear ? [
                'id' => $a->academicYear->id,
                'name' => $a->academicYear->name,
            ] : null,
            'classroom' => $a->relationLoaded('classroom') && $a->classroom ? [
                'id' => $a->classroom->id,
                'name' => $a->classroom->name,
            ] : null,
            'student' => $a->relationLoaded('student') && $a->student ? [
                'id' => $a->student->id,
                'admission_number' => $a->student->admission_number,
            ] : null,
            'guardians' => $a->relationLoaded('guardians')
                ? $a->guardians->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'relationship' => $g->relationship,
                    'phone' => $g->phone,
                    'email' => $g->email,
                ])->values()
                : [],
            'created_at' => optional($a->created_at)?->toIso8601String(),
        ];
    }
}
