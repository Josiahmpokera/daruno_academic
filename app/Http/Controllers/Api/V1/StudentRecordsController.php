<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentGuardian;
use App\Models\StudentHealthRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Parents/guardians, medical, previous school, documents, status — tenant scoped.
 */
class StudentRecordsController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Guardians / Parents ───────────────────────────────────────

    public function guardiansIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $studentId = $request->integer('student_id') ?: null;
            $relationship = $request->string('relationship')->toString();

            $query = StudentGuardian::query()
                ->where('school_id', $schoolId)
                ->with(['student:id,first_name,middle_name,last_name,admission_number,status'])
                ->orderByDesc('id');

            if ($studentId) {
                $query->where('student_id', $studentId);
            }
            if ($relationship !== '') {
                $query->where('relationship', $relationship);
            }
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('full_name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('nida_number', 'like', "%{$q}%")
                        ->orWhereHas('student', function ($s) use ($q) {
                            $s->where('first_name', 'like', "%{$q}%")
                                ->orWhere('last_name', 'like', "%{$q}%")
                                ->orWhere('admission_number', 'like', "%{$q}%");
                        });
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $items = collect($page->items())->map(fn (StudentGuardian $g) => $this->transformGuardian($g));

            return $this->success([
                'guardians' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function guardiansStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer', 'exists:students,id'],
                'relationship' => ['required', 'string', 'max:40'],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:255'],
                'occupation' => ['nullable', 'string', 'max:120'],
                'nida_number' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:255'],
                'is_primary' => ['nullable', 'boolean'],
            ]);

            $student = Student::query()
                ->where('school_id', $schoolId)
                ->where('id', $data['student_id'])
                ->firstOrFail();

            $guardian = DB::transaction(function () use ($schoolId, $student, $data) {
                if (! empty($data['is_primary'])) {
                    StudentGuardian::query()
                        ->where('school_id', $schoolId)
                        ->where('student_id', $student->id)
                        ->update(['is_primary' => false]);
                }

                return StudentGuardian::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'relationship' => $data['relationship'],
                    'full_name' => $data['full_name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'occupation' => $data['occupation'] ?? null,
                    'nida_number' => $data['nida_number'] ?? null,
                    'address' => $data['address'] ?? null,
                    'is_primary' => (bool) ($data['is_primary'] ?? false),
                ]);
            });

            return $this->success([
                'guardian' => $this->transformGuardian($guardian->load('student')),
            ], 'Guardian saved', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function guardiansUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $guardian = StudentGuardian::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $data = $request->validate([
                'relationship' => ['sometimes', 'string', 'max:40'],
                'full_name' => ['sometimes', 'string', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
                'nida_number' => ['sometimes', 'nullable', 'string', 'max:50'],
                'address' => ['sometimes', 'nullable', 'string', 'max:255'],
                'is_primary' => ['sometimes', 'boolean'],
                'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            ]);

            if (isset($data['student_id'])) {
                Student::query()
                    ->where('school_id', $schoolId)
                    ->where('id', $data['student_id'])
                    ->firstOrFail();
            }

            DB::transaction(function () use ($schoolId, $guardian, $data) {
                if (array_key_exists('is_primary', $data) && $data['is_primary']) {
                    StudentGuardian::query()
                        ->where('school_id', $schoolId)
                        ->where('student_id', $data['student_id'] ?? $guardian->student_id)
                        ->where('id', '!=', $guardian->id)
                        ->update(['is_primary' => false]);
                }
                $guardian->fill($data);
                $guardian->save();
            });

            return $this->success([
                'guardian' => $this->transformGuardian($guardian->fresh()->load('student')),
            ], 'Guardian updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function guardiansDestroy(Request $request, int $id)
    {
        try {
            $guardian = StudentGuardian::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $guardian->delete();

            return $this->success(['id' => $id], 'Guardian removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ── Medical ───────────────────────────────────────────────────

    public function medicalIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();

            $query = Student::query()
                ->where('school_id', $schoolId)
                ->with(['healthRecord', 'currentEnrollment.classroom'])
                ->orderBy('last_name')
                ->orderBy('first_name');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('admission_number', 'like', "%{$q}%")
                        ->orWhere('blood_group', 'like', "%{$q}%")
                        ->orWhere('disability', 'like', "%{$q}%");
                });
            }

            // Only those with any medical-ish data, or all if include_all
            if (! $request->boolean('include_all', true)) {
                $query->where(function ($b) {
                    $b->whereNotNull('blood_group')
                        ->orWhereNotNull('disability')
                        ->orWhereHas('healthRecord');
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $items = collect($page->items())->map(fn (Student $s) => $this->transformMedical($s));

            return $this->success([
                'records' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function medicalShow(Request $request, int $studentId)
    {
        try {
            $student = Student::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['healthRecord', 'currentEnrollment.classroom'])
                ->findOrFail($studentId);

            return $this->success(['record' => $this->transformMedical($student)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function medicalUpsert(Request $request, int $studentId)
    {
        try {
            $schoolId = $this->schoolId($request);
            $student = Student::query()
                ->where('school_id', $schoolId)
                ->findOrFail($studentId);

            $data = $request->validate([
                'blood_group' => ['nullable', 'string', 'max:10'],
                'disability' => ['nullable', 'string', 'max:120'],
                'allergies' => ['nullable', 'string'],
                'chronic_conditions' => ['nullable', 'string'],
                'notes' => ['nullable', 'string'],
            ]);

            DB::transaction(function () use ($schoolId, $student, $data) {
                if (array_key_exists('blood_group', $data)) {
                    $student->blood_group = $data['blood_group'];
                }
                if (array_key_exists('disability', $data)) {
                    $student->disability = $data['disability'];
                }
                $student->save();

                StudentHealthRecord::query()->updateOrCreate(
                    [
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                    ],
                    [
                        'blood_group' => $data['blood_group'] ?? $student->blood_group,
                        'allergies' => $data['allergies'] ?? null,
                        'chronic_conditions' => $data['chronic_conditions'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]
                );
            });

            $student->load(['healthRecord', 'currentEnrollment.classroom']);

            return $this->success([
                'record' => $this->transformMedical($student->fresh(['healthRecord', 'currentEnrollment.classroom'])),
            ], 'Medical information saved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Previous school ───────────────────────────────────────────

    public function previousSchoolIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $hasPrevious = $request->string('has_previous')->toString(); // yes|no|''

            $query = Student::query()
                ->where('school_id', $schoolId)
                ->with(['currentEnrollment.classroom'])
                ->orderBy('last_name');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('admission_number', 'like', "%{$q}%")
                        ->orWhere('previous_school', 'like', "%{$q}%")
                        ->orWhere('previous_class', 'like', "%{$q}%");
                });
            }

            if ($hasPrevious === 'yes') {
                $query->whereNotNull('previous_school')->where('previous_school', '!=', '');
            } elseif ($hasPrevious === 'no') {
                $query->where(function ($b) {
                    $b->whereNull('previous_school')->orWhere('previous_school', '');
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $items = collect($page->items())->map(function (Student $s) {
                $cls = $s->currentEnrollment?->classroom;

                return [
                    'id' => $s->id,
                    'full_name' => $s->full_name,
                    'admission_number' => $s->admission_number,
                    'status' => $s->status,
                    'previous_school' => $s->previous_school,
                    'previous_class' => $s->previous_class,
                    'admission_date' => optional($s->admission_date)?->toDateString(),
                    'classroom' => $cls ? [
                        'id' => $cls->id,
                        'name' => $cls->name,
                    ] : null,
                ];
            });

            return $this->success([
                'students' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function previousSchoolUpdate(Request $request, int $studentId)
    {
        try {
            $student = Student::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($studentId);

            $data = $request->validate([
                'previous_school' => ['nullable', 'string', 'max:255'],
                'previous_class' => ['nullable', 'string', 'max:80'],
                'admission_date' => ['nullable', 'date'],
            ]);

            $student->fill($data);
            $student->save();

            return $this->success([
                'student' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'admission_number' => $student->admission_number,
                    'previous_school' => $student->previous_school,
                    'previous_class' => $student->previous_class,
                    'admission_date' => optional($student->admission_date)?->toDateString(),
                ],
            ], 'Previous school updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Documents & certificates ──────────────────────────────────

    public function documentsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $type = $request->string('document_type')->toString();
            $studentId = $request->integer('student_id') ?: null;
            $status = $request->string('status')->toString();

            $query = StudentDocument::query()
                ->where('school_id', $schoolId)
                ->with(['student:id,first_name,middle_name,last_name,admission_number'])
                ->orderByDesc('id');

            if ($studentId) {
                $query->where('student_id', $studentId);
            }
            if ($type !== '') {
                $query->where('document_type', $type);
            }
            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('title', 'like', "%{$q}%")
                        ->orWhere('reference_number', 'like', "%{$q}%")
                        ->orWhere('file_name', 'like', "%{$q}%")
                        ->orWhereHas('student', function ($s) use ($q) {
                            $s->where('first_name', 'like', "%{$q}%")
                                ->orWhere('last_name', 'like', "%{$q}%")
                                ->orWhere('admission_number', 'like', "%{$q}%");
                        });
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            $items = collect($page->items())->map(fn (StudentDocument $d) => $this->transformDocument($d));

            return $this->success([
                'documents' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'document_types' => [
                    'birth_certificate',
                    'transfer_letter',
                    'medical_report',
                    'photo',
                    'id',
                    'report_card',
                    'other',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function documentsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer', 'exists:students,id'],
                'title' => ['required', 'string', 'max:255'],
                'document_type' => ['required', 'string', 'max:60'],
                'file_name' => ['nullable', 'string', 'max:255'],
                'file_path' => ['nullable', 'string', 'max:500'],
                'mime_type' => ['nullable', 'string', 'max:120'],
                'file_size' => ['nullable', 'integer', 'min:0'],
                'reference_number' => ['nullable', 'string', 'max:120'],
                'issued_on' => ['nullable', 'date'],
                'expires_on' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
                'status' => ['nullable', 'in:active,archived'],
            ]);

            Student::query()
                ->where('school_id', $schoolId)
                ->where('id', $data['student_id'])
                ->firstOrFail();

            $doc = StudentDocument::query()->create([
                'school_id' => $schoolId,
                'student_id' => $data['student_id'],
                'title' => $data['title'],
                'document_type' => $data['document_type'],
                'file_name' => $data['file_name'] ?? null,
                'file_path' => $data['file_path'] ?? null,
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'issued_on' => $data['issued_on'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success([
                'document' => $this->transformDocument($doc->load('student')),
            ], 'Document recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function documentsUpdate(Request $request, int $id)
    {
        try {
            $doc = StudentDocument::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'document_type' => ['sometimes', 'string', 'max:60'],
                'file_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'file_path' => ['sometimes', 'nullable', 'string', 'max:500'],
                'mime_type' => ['sometimes', 'nullable', 'string', 'max:120'],
                'file_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'reference_number' => ['sometimes', 'nullable', 'string', 'max:120'],
                'issued_on' => ['sometimes', 'nullable', 'date'],
                'expires_on' => ['sometimes', 'nullable', 'date'],
                'notes' => ['sometimes', 'nullable', 'string'],
                'status' => ['sometimes', 'in:active,archived'],
            ]);

            $doc->fill($data);
            $doc->save();

            return $this->success([
                'document' => $this->transformDocument($doc->fresh()->load('student')),
            ], 'Document updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function documentsDestroy(Request $request, int $id)
    {
        try {
            $doc = StudentDocument::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $doc->delete();

            return $this->success(['id' => $id], 'Document removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ── Status management ─────────────────────────────────────────

    public function statusSummary(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);

            $byStatus = Student::query()
                ->where('school_id', $schoolId)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->get()
                ->map(fn ($r) => ['status' => $r->status ?: 'unknown', 'total' => (int) $r->total]);

            return $this->success([
                'by_status' => $byStatus,
                'total' => Student::query()->where('school_id', $schoolId)->count(),
                'statuses' => ['active', 'transferred', 'graduated', 'inactive'],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function statusBulkUpdate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'ids' => ['required', 'array', 'min:1', 'max:200'],
                'ids.*' => ['integer'],
                'status' => ['required', 'in:active,transferred,graduated,inactive'],
                'notes' => ['nullable', 'string'],
            ]);

            $updated = Student::query()
                ->where('school_id', $schoolId)
                ->whereIn('id', $data['ids'])
                ->update([
                    'status' => $data['status'],
                    ...(array_key_exists('notes', $data) && $data['notes'] !== null
                        ? ['notes' => $data['notes']]
                        : []),
                ]);

            return $this->success([
                'updated' => $updated,
                'status' => $data['status'],
                'ids' => $data['ids'],
            ], "Updated {$updated} student(s)");
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Transformers ──────────────────────────────────────────────

    protected function transformGuardian(StudentGuardian $g): array
    {
        $student = $g->relationLoaded('student') ? $g->student : null;

        return [
            'id' => $g->id,
            'student_id' => $g->student_id,
            'relationship' => $g->relationship,
            'full_name' => $g->full_name,
            'phone' => $g->phone,
            'email' => $g->email,
            'occupation' => $g->occupation,
            'nida_number' => $g->nida_number,
            'address' => $g->address,
            'is_primary' => (bool) $g->is_primary,
            'student' => $student ? [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'admission_number' => $student->admission_number,
                'status' => $student->status,
            ] : null,
            'created_at' => optional($g->created_at)?->toIso8601String(),
        ];
    }

    protected function transformMedical(Student $s): array
    {
        $health = $s->relationLoaded('healthRecord') ? $s->healthRecord : $s->healthRecord()->first();
        $cls = $s->currentEnrollment?->classroom;

        return [
            'student_id' => $s->id,
            'full_name' => $s->full_name,
            'admission_number' => $s->admission_number,
            'status' => $s->status,
            'gender' => $s->gender,
            'date_of_birth' => optional($s->date_of_birth)?->toDateString(),
            'blood_group' => $health?->blood_group ?: $s->blood_group,
            'disability' => $s->disability,
            'allergies' => $health?->allergies,
            'chronic_conditions' => $health?->chronic_conditions,
            'notes' => $health?->notes,
            'classroom' => $cls ? ['id' => $cls->id, 'name' => $cls->name] : null,
            'has_health_record' => (bool) $health,
        ];
    }

    protected function transformDocument(StudentDocument $d): array
    {
        $student = $d->relationLoaded('student') ? $d->student : null;

        return [
            'id' => $d->id,
            'student_id' => $d->student_id,
            'title' => $d->title,
            'document_type' => $d->document_type,
            'file_name' => $d->file_name,
            'file_path' => $d->file_path,
            'mime_type' => $d->mime_type,
            'file_size' => $d->file_size,
            'reference_number' => $d->reference_number,
            'issued_on' => optional($d->issued_on)?->toDateString(),
            'expires_on' => optional($d->expires_on)?->toDateString(),
            'notes' => $d->notes,
            'status' => $d->status,
            'student' => $student ? [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'admission_number' => $student->admission_number,
            ] : null,
            'created_at' => optional($d->created_at)?->toIso8601String(),
        ];
    }
}
