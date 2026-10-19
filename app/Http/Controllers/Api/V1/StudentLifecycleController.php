<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentGraduation;
use App\Models\StudentTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transfers, graduation, and alumni — tenant scoped lifecycle.
 */
class StudentLifecycleController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Transfers ─────────────────────────────────────────────────

    public function transfersIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString();
            $direction = $request->string('direction')->toString();

            $query = StudentTransfer::query()
                ->where('school_id', $schoolId)
                ->with([
                    'student:id,first_name,middle_name,last_name,admission_number,status',
                    'processor:id,name,email',
                ])
                ->orderByDesc('id');

            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($direction !== '') {
                $query->where('direction', $direction);
            }
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('other_school_name', 'like', "%{$q}%")
                        ->orWhere('reference_number', 'like', "%{$q}%")
                        ->orWhere('reason', 'like', "%{$q}%")
                        ->orWhereHas('student', function ($s) use ($q) {
                            $s->where('first_name', 'like', "%{$q}%")
                                ->orWhere('last_name', 'like', "%{$q}%")
                                ->orWhere('admission_number', 'like', "%{$q}%");
                        });
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $summary = [
                'pending' => StudentTransfer::query()->where('school_id', $schoolId)->where('status', 'pending')->count(),
                'approved' => StudentTransfer::query()->where('school_id', $schoolId)->where('status', 'approved')->count(),
                'completed' => StudentTransfer::query()->where('school_id', $schoolId)->where('status', 'completed')->count(),
                'cancelled' => StudentTransfer::query()->where('school_id', $schoolId)->where('status', 'cancelled')->count(),
            ];

            return $this->success([
                'transfers' => collect($page->items())->map(fn (StudentTransfer $t) => $this->transformTransfer($t)),
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

    public function transfersStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer', $this->tenantExists($request, 'students')],
                'direction' => ['required', 'in:outgoing,incoming'],
                'other_school_name' => ['nullable', 'string', 'max:255'],
                'other_school_code' => ['nullable', 'string', 'max:80'],
                'transfer_date' => ['nullable', 'date'],
                'reason' => ['nullable', 'string', 'max:255'],
                'reference_number' => ['nullable', 'string', 'max:80'],
                'status' => ['nullable', 'in:pending,approved,completed,cancelled'],
                'notes' => ['nullable', 'string'],
                'apply_status' => ['nullable', 'boolean'], // complete immediately
            ]);

            $student = Student::query()
                ->where('school_id', $schoolId)
                ->where('id', $data['student_id'])
                ->firstOrFail();

            $status = $data['status'] ?? 'pending';
            if (! empty($data['apply_status'])) {
                $status = 'completed';
            }

            $transfer = DB::transaction(function () use ($schoolId, $student, $data, $status, $request) {
                $transfer = StudentTransfer::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'direction' => $data['direction'],
                    'other_school_name' => $data['other_school_name'] ?? null,
                    'other_school_code' => $data['other_school_code'] ?? null,
                    'transfer_date' => $data['transfer_date'] ?? now()->toDateString(),
                    'reason' => $data['reason'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'status' => $status,
                    'notes' => $data['notes'] ?? null,
                    'processed_by' => $status === 'completed' ? $request->user()?->id : null,
                    'processed_at' => $status === 'completed' ? now() : null,
                ]);

                if ($status === 'completed') {
                    $this->applyTransferToStudent($student, $transfer);
                }

                return $transfer->load(['student', 'processor']);
            });

            return $this->success([
                'transfer' => $this->transformTransfer($transfer),
            ], 'Transfer recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function transfersUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $transfer = StudentTransfer::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $data = $request->validate([
                'direction' => ['sometimes', 'in:outgoing,incoming'],
                'other_school_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'other_school_code' => ['sometimes', 'nullable', 'string', 'max:80'],
                'transfer_date' => ['sometimes', 'nullable', 'date'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
                'reference_number' => ['sometimes', 'nullable', 'string', 'max:80'],
                'status' => ['sometimes', 'in:pending,approved,completed,cancelled'],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            $prevStatus = $transfer->status;

            DB::transaction(function () use ($transfer, $data, $prevStatus, $request, $schoolId) {
                $transfer->fill($data);

                if (isset($data['status']) && $data['status'] === 'completed' && $prevStatus !== 'completed') {
                    $transfer->processed_by = $request->user()?->id;
                    $transfer->processed_at = now();
                    $transfer->save();
                    $student = Student::query()
                        ->where('school_id', $schoolId)
                        ->findOrFail($transfer->student_id);
                    $this->applyTransferToStudent($student, $transfer);
                } else {
                    $transfer->save();
                }
            });

            return $this->success([
                'transfer' => $this->transformTransfer($transfer->fresh()->load(['student', 'processor'])),
            ], 'Transfer updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function transfersDestroy(Request $request, int $id)
    {
        try {
            $transfer = StudentTransfer::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($transfer->status === 'completed') {
                return $this->error('Completed transfers cannot be deleted. Cancel is not available after completion.', 422);
            }

            $transfer->delete();

            return $this->success(['id' => $id], 'Transfer removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    protected function applyTransferToStudent(Student $student, StudentTransfer $transfer): void
    {
        if ($transfer->direction === 'outgoing') {
            $student->status = 'transferred';
            $student->save();
            Enrollment::query()
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->update(['status' => 'transferred']);
        } elseif ($transfer->direction === 'incoming') {
            if ($student->status === 'transferred' || $student->status === 'inactive') {
                $student->status = 'active';
                $student->save();
            }
        }
    }

    // ── Graduation ────────────────────────────────────────────────

    public function graduationsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $yearId = $request->integer('academic_year_id') ?: null;

            $query = StudentGraduation::query()
                ->where('school_id', $schoolId)
                ->with([
                    'student:id,first_name,middle_name,last_name,admission_number,status,alumni_year',
                    'academicYear:id,name',
                    'classroom:id,name',
                    'processor:id,name,email',
                ])
                ->orderByDesc('graduation_date')
                ->orderByDesc('id');

            if ($yearId) {
                $query->where('academic_year_id', $yearId);
            }
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('certificate_number', 'like', "%{$q}%")
                        ->orWhere('level_completed', 'like', "%{$q}%")
                        ->orWhereHas('student', function ($s) use ($q) {
                            $s->where('first_name', 'like', "%{$q}%")
                                ->orWhere('last_name', 'like', "%{$q}%")
                                ->orWhere('admission_number', 'like', "%{$q}%");
                        });
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'graduations' => collect($page->items())->map(fn (StudentGraduation $g) => $this->transformGraduation($g)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'summary' => [
                    'total' => StudentGraduation::query()->where('school_id', $schoolId)->count(),
                    'graduated_students' => Student::query()->where('school_id', $schoolId)->where('status', 'graduated')->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function graduationsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer', $this->tenantExists($request, 'students')],
                'academic_year_id' => ['nullable', 'integer', $this->tenantExists($request, 'academic_years')],
                'classroom_id' => ['nullable', 'integer', $this->tenantExists($request, 'classrooms')],
                'graduation_date' => ['nullable', 'date'],
                'certificate_number' => ['nullable', 'string', 'max:80'],
                'level_completed' => ['nullable', 'string', 'max:80'],
                'remarks' => ['nullable', 'string'],
                'student_ids' => ['nullable', 'array'], // bulk
                'student_ids.*' => ['integer'],
            ]);

            // Bulk graduation
            if (! empty($data['student_ids'])) {
                $ids = $data['student_ids'];
                $created = 0;
                DB::transaction(function () use ($schoolId, $ids, $data, $request, &$created) {
                    foreach ($ids as $sid) {
                        $student = Student::query()
                            ->where('school_id', $schoolId)
                            ->where('id', $sid)
                            ->first();
                        if (! $student) {
                            continue;
                        }
                        $this->graduateStudent($schoolId, $student, $data, $request->user()?->id);
                        $created++;
                    }
                });

                return $this->success([
                    'graduated' => $created,
                ], "Graduated {$created} student(s)", 201);
            }

            $student = Student::query()
                ->where('school_id', $schoolId)
                ->where('id', $data['student_id'])
                ->firstOrFail();

            $graduation = DB::transaction(function () use ($schoolId, $student, $data, $request) {
                return $this->graduateStudent($schoolId, $student, $data, $request->user()?->id);
            });

            return $this->success([
                'graduation' => $this->transformGraduation($graduation->load(['student', 'academicYear', 'classroom', 'processor'])),
            ], 'Student graduated', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function graduationsUpdate(Request $request, int $id)
    {
        try {
            $grad = StudentGraduation::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'academic_year_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists($request, 'academic_years')],
                'classroom_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists($request, 'classrooms')],
                'graduation_date' => ['sometimes', 'nullable', 'date'],
                'certificate_number' => ['sometimes', 'nullable', 'string', 'max:80'],
                'level_completed' => ['sometimes', 'nullable', 'string', 'max:80'],
                'remarks' => ['sometimes', 'nullable', 'string'],
            ]);

            $grad->fill($data);
            $grad->save();

            return $this->success([
                'graduation' => $this->transformGraduation($grad->fresh()->load(['student', 'academicYear', 'classroom', 'processor'])),
            ], 'Graduation record updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function graduationsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $grad = StudentGraduation::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            DB::transaction(function () use ($grad, $schoolId) {
                $studentId = $grad->student_id;
                $grad->delete();
                // Revert status only if no other graduation and still marked graduated
                $student = Student::query()->where('school_id', $schoolId)->find($studentId);
                if ($student && $student->status === 'graduated') {
                    $student->status = 'active';
                    $student->save();
                }
            });

            return $this->success(['id' => $id], 'Graduation record removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    protected function graduateStudent(int $schoolId, Student $student, array $data, ?int $userId): StudentGraduation
    {
        $grad = StudentGraduation::query()->updateOrCreate(
            [
                'school_id' => $schoolId,
                'student_id' => $student->id,
            ],
            [
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'classroom_id' => $data['classroom_id'] ?? $student->currentEnrollment?->classroom_id,
                'graduation_date' => $data['graduation_date'] ?? now()->toDateString(),
                'certificate_number' => $data['certificate_number'] ?? null,
                'level_completed' => $data['level_completed'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'processed_by' => $userId,
            ]
        );

        $student->status = 'graduated';
        $year = null;
        if (! empty($data['graduation_date'])) {
            $year = (int) date('Y', strtotime($data['graduation_date']));
        }
        $student->alumni_year = $year ?: (int) now()->format('Y');
        $student->save();

        Enrollment::query()
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->update(['status' => 'completed']);

        return $grad;
    }

    // ── Alumni ────────────────────────────────────────────────────

    public function alumniIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $year = $request->integer('alumni_year') ?: null;

            $query = Student::query()
                ->where('school_id', $schoolId)
                ->where('status', 'graduated')
                ->with(['graduation.academicYear', 'graduation.classroom'])
                ->orderByDesc('alumni_year')
                ->orderBy('last_name');

            if ($year) {
                $query->where('alumni_year', $year);
            }
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('admission_number', 'like', "%{$q}%")
                        ->orWhere('alumni_email', 'like', "%{$q}%")
                        ->orWhere('alumni_phone', 'like', "%{$q}%")
                        ->orWhere('alumni_occupation', 'like', "%{$q}%");
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $items = collect($page->items())->map(fn (Student $s) => $this->transformAlumni($s));

            $years = Student::query()
                ->where('school_id', $schoolId)
                ->where('status', 'graduated')
                ->whereNotNull('alumni_year')
                ->distinct()
                ->orderByDesc('alumni_year')
                ->pluck('alumni_year')
                ->values();

            return $this->success([
                'alumni' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'years' => $years,
                'summary' => [
                    'total' => Student::query()->where('school_id', $schoolId)->where('status', 'graduated')->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function alumniUpdate(Request $request, int $studentId)
    {
        try {
            $student = Student::query()
                ->where('school_id', $this->schoolId($request))
                ->where('status', 'graduated')
                ->findOrFail($studentId);

            $data = $request->validate([
                'alumni_year' => ['sometimes', 'nullable', 'integer', 'min:1970', 'max:2100'],
                'alumni_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'alumni_email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'alumni_occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
                'alumni_notes' => ['sometimes', 'nullable', 'string'],
            ]);

            $student->fill($data);
            $student->save();

            return $this->success([
                'alumni' => $this->transformAlumni($student->fresh()->load(['graduation.academicYear', 'graduation.classroom'])),
            ], 'Alumni profile updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Transformers ──────────────────────────────────────────────

    protected function transformTransfer(StudentTransfer $t): array
    {
        $student = $t->relationLoaded('student') ? $t->student : null;

        return [
            'id' => $t->id,
            'student_id' => $t->student_id,
            'direction' => $t->direction,
            'other_school_name' => $t->other_school_name,
            'other_school_code' => $t->other_school_code,
            'transfer_date' => optional($t->transfer_date)?->toDateString(),
            'reason' => $t->reason,
            'reference_number' => $t->reference_number,
            'status' => $t->status,
            'notes' => $t->notes,
            'processed_at' => optional($t->processed_at)?->toIso8601String(),
            'processor' => $t->relationLoaded('processor') && $t->processor ? [
                'id' => $t->processor->id,
                'name' => $t->processor->name,
            ] : null,
            'student' => $student ? [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'admission_number' => $student->admission_number,
                'status' => $student->status,
            ] : null,
            'created_at' => optional($t->created_at)?->toIso8601String(),
        ];
    }

    protected function transformGraduation(StudentGraduation $g): array
    {
        $student = $g->relationLoaded('student') ? $g->student : null;

        return [
            'id' => $g->id,
            'student_id' => $g->student_id,
            'academic_year_id' => $g->academic_year_id,
            'classroom_id' => $g->classroom_id,
            'graduation_date' => optional($g->graduation_date)?->toDateString(),
            'certificate_number' => $g->certificate_number,
            'level_completed' => $g->level_completed,
            'remarks' => $g->remarks,
            'academic_year' => $g->relationLoaded('academicYear') && $g->academicYear ? [
                'id' => $g->academicYear->id,
                'name' => $g->academicYear->name,
            ] : null,
            'classroom' => $g->relationLoaded('classroom') && $g->classroom ? [
                'id' => $g->classroom->id,
                'name' => $g->classroom->name,
            ] : null,
            'student' => $student ? [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'admission_number' => $student->admission_number,
                'status' => $student->status,
                'alumni_year' => $student->alumni_year,
            ] : null,
            'created_at' => optional($g->created_at)?->toIso8601String(),
        ];
    }

    protected function transformAlumni(Student $s): array
    {
        $grad = $s->relationLoaded('graduation') ? $s->graduation : null;

        return [
            'id' => $s->id,
            'full_name' => $s->full_name,
            'admission_number' => $s->admission_number,
            'status' => $s->status,
            'alumni_year' => $s->alumni_year,
            'alumni_phone' => $s->alumni_phone,
            'alumni_email' => $s->alumni_email,
            'alumni_occupation' => $s->alumni_occupation,
            'alumni_notes' => $s->alumni_notes,
            'graduation' => $grad ? [
                'id' => $grad->id,
                'graduation_date' => optional($grad->graduation_date)?->toDateString(),
                'certificate_number' => $grad->certificate_number,
                'level_completed' => $grad->level_completed,
                'academic_year' => $grad->academicYear?->name,
                'classroom' => $grad->classroom?->name,
            ] : null,
        ];
    }
}
