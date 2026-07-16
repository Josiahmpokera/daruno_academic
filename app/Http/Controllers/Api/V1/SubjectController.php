<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomSubject;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectCategory;
use App\Models\SubjectCombination;
use App\Models\TeacherSubjectAllocation;
use App\Models\Term;
use App\Support\EducationStages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * TAMISEMI / NECTA subject management for primary & secondary schools.
 */
class SubjectController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public const STAGES = [
        'pre_primary',
        'primary',
        'ordinary_level',
        'advanced_level',
        'all',
    ];

    // ─── Categories ───────────────────────────────────────────────

    public function categoriesIndex(Request $request)
    {
        try {
            $rows = SubjectCategory::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount('subjects')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return $this->success(['categories' => $rows]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function categoriesStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string', 'max:1000'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $row = SubjectCategory::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success(['category' => $row->loadCount('subjects')], 'Category created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function categoriesUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $row = SubjectCategory::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['sometimes', 'nullable', 'string', 'max:40'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $row->fill($data);
            $row->save();

            return $this->success(['category' => $row->loadCount('subjects')], 'Category updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function categoriesDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $row = SubjectCategory::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($row->subjects()->exists()) {
                $row->is_active = false;
                $row->save();

                return $this->success([
                    'category' => $row,
                    'deactivated' => true,
                ], 'Category has subjects and was deactivated');
            }
            $row->delete();

            return $this->success(['id' => $id], 'Category deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ─── Subjects ─────────────────────────────────────────────────

    public function subjectsIndex(Request $request)
    {
        try {
            $school = $this->school($request);
            $schoolId = (int) $school->id;
            $offered = $school->offeredStages();

            $q = Subject::query()
                ->where('school_id', $schoolId)
                ->with(['category:id,name,code'])
                ->withCount(['classroomSubjects', 'teacherAllocations']);

            // Default: only stages this school offers (+ "all")
            $includeAllStages = filter_var(
                $request->input('include_all_stages', false),
                FILTER_VALIDATE_BOOLEAN
            );

            if ($request->filled('stage')) {
                $stage = $request->string('stage')->toString();
                $q->where(function ($w) use ($stage) {
                    $w->where('education_stage', $stage)->orWhere('education_stage', 'all');
                });
            } elseif (! $includeAllStages && $offered !== []) {
                $q->where(function ($w) use ($offered) {
                    $w->whereIn('education_stage', $offered)
                        ->orWhere('education_stage', 'all');
                });
            }

            if ($request->filled('category_id')) {
                $q->where('subject_category_id', (int) $request->input('category_id'));
            }
            if ($request->filled('active')) {
                $q->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
            }
            if ($request->filled('q')) {
                $term = '%'.$request->string('q')->toString().'%';
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('necta_code', 'like', $term);
                });
            }

            $subjects = $q->orderBy('sort_order')->orderBy('name')->get();

            return $this->success([
                'subjects' => $subjects,
                'education_stages' => self::STAGES,
                'school_education_stages' => $offered,
                'education_stage_catalog' => EducationStages::catalog(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function subjectsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $this->validateSubject($request, $schoolId);

            if (! empty($data['subject_category_id'])) {
                $this->assertCategory($schoolId, (int) $data['subject_category_id']);
            }

            $subject = Subject::query()->create([
                'school_id' => $schoolId,
                'subject_category_id' => $data['subject_category_id'] ?? null,
                'name' => $data['name'],
                'short_name' => $data['short_name'] ?? null,
                'code' => $data['code'] ?? null,
                'necta_code' => $data['necta_code'] ?? null,
                'education_stage' => $data['education_stage'] ?? 'all',
                'is_core' => $data['is_core'] ?? true,
                'is_compulsory' => $data['is_compulsory'] ?? true,
                'is_active' => $data['is_active'] ?? true,
                'weekly_periods' => $data['weekly_periods'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'description' => $data['description'] ?? null,
            ]);

            return $this->success([
                'subject' => $subject->load(['category:id,name,code'])->loadCount(['classroomSubjects', 'teacherAllocations']),
            ], 'Subject created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function subjectsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $subject = Subject::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $this->validateSubject($request, $schoolId, true);

            if (array_key_exists('subject_category_id', $data) && $data['subject_category_id']) {
                $this->assertCategory($schoolId, (int) $data['subject_category_id']);
            }

            $subject->fill($data);
            $subject->save();

            return $this->success([
                'subject' => $subject->load(['category:id,name,code'])->loadCount(['classroomSubjects', 'teacherAllocations']),
            ], 'Subject updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function subjectsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $subject = Subject::query()->where('school_id', $schoolId)->findOrFail($id);

            $inUse = $subject->classroomSubjects()->exists()
                || $subject->teacherAllocations()->exists()
                || $subject->combinations()->exists();

            if ($inUse) {
                $subject->is_active = false;
                $subject->save();

                return $this->success([
                    'subject' => $subject,
                    'deactivated' => true,
                ], 'Subject is in use and was deactivated');
            }

            $subject->delete();

            return $this->success(['id' => $id], 'Subject deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ─── Combinations ─────────────────────────────────────────────

    public function combinationsIndex(Request $request)
    {
        try {
            $school = $this->school($request);
            $offered = $school->offeredStages();

            $q = SubjectCombination::query()
                ->where('school_id', (int) $school->id)
                ->with(['subjects:id,name,code,short_name']);

            // Hide A-level combos if school does not offer A-level, etc.
            if ($offered !== [] && ! filter_var($request->input('include_all_stages', false), FILTER_VALIDATE_BOOLEAN)) {
                $q->where(function ($w) use ($offered) {
                    $w->whereIn('education_stage', $offered)
                        ->orWhere('education_stage', 'all');
                });
            }

            $rows = $q->orderBy('code')
                ->get()
                ->map(function (SubjectCombination $c) {
                    return $this->transformCombination($c);
                });

            return $this->success([
                'combinations' => $rows,
                'school_education_stages' => $offered,
                'education_stage_catalog' => EducationStages::catalog(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function combinationsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'code' => ['required', 'string', 'max:20'],
                'education_stage' => ['nullable', Rule::in(self::STAGES)],
                'description' => ['nullable', 'string', 'max:1000'],
                'is_active' => ['nullable', 'boolean'],
                'subject_ids' => ['nullable', 'array'],
                'subject_ids.*' => ['integer'],
                'subjects' => ['nullable', 'array'],
                'subjects.*.subject_id' => ['required_with:subjects', 'integer'],
                'subjects.*.is_principal' => ['nullable', 'boolean'],
                'subjects.*.sort_order' => ['nullable', 'integer'],
            ]);

            $combo = DB::transaction(function () use ($schoolId, $data) {
                $combo = SubjectCombination::query()->create([
                    'school_id' => $schoolId,
                    'name' => $data['name'],
                    'code' => strtoupper($data['code']),
                    'education_stage' => $data['education_stage'] ?? 'advanced_level',
                    'description' => $data['description'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);
                $this->syncCombinationSubjects($schoolId, $combo, $data);

                return $combo;
            });

            return $this->success([
                'combination' => $this->transformCombination($combo->load('subjects')),
            ], 'Combination created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function combinationsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $combo = SubjectCombination::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'code' => ['sometimes', 'string', 'max:20'],
                'education_stage' => ['sometimes', Rule::in(self::STAGES)],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'is_active' => ['sometimes', 'boolean'],
                'subject_ids' => ['nullable', 'array'],
                'subject_ids.*' => ['integer'],
                'subjects' => ['nullable', 'array'],
                'subjects.*.subject_id' => ['required_with:subjects', 'integer'],
                'subjects.*.is_principal' => ['nullable', 'boolean'],
                'subjects.*.sort_order' => ['nullable', 'integer'],
            ]);

            DB::transaction(function () use ($combo, $data, $schoolId) {
                if (isset($data['code'])) {
                    $data['code'] = strtoupper($data['code']);
                }
                $combo->fill(collect($data)->except(['subject_ids', 'subjects'])->all());
                $combo->save();
                if (array_key_exists('subject_ids', $data) || array_key_exists('subjects', $data)) {
                    $this->syncCombinationSubjects($schoolId, $combo, $data);
                }
            });

            return $this->success([
                'combination' => $this->transformCombination($combo->fresh()->load('subjects')),
            ], 'Combination updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function combinationsDestroy(Request $request, int $id)
    {
        try {
            $combo = SubjectCombination::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $combo->subjects()->detach();
            $combo->delete();

            return $this->success(['id' => $id], 'Combination deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ─── Class assignment ─────────────────────────────────────────

    public function classAssignmentsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = ClassroomSubject::query()
                ->where('school_id', $schoolId)
                ->with([
                    'classroom:id,name,code',
                    'subject:id,name,code,short_name,education_stage',
                    'academicYear:id,name,is_current',
                ]);

            if ($request->filled('classroom_id')) {
                $q->where('classroom_id', (int) $request->input('classroom_id'));
            }
            if ($request->filled('academic_year_id')) {
                $q->where('academic_year_id', (int) $request->input('academic_year_id'));
            }
            if ($request->filled('subject_id')) {
                $q->where('subject_id', (int) $request->input('subject_id'));
            }

            $rows = $q->orderByDesc('id')->get();

            return $this->success(['assignments' => $rows]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function classAssignmentsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'classroom_id' => ['required', 'integer'],
                'subject_id' => ['required', 'integer'],
                'academic_year_id' => ['required', 'integer'],
                'is_compulsory' => ['nullable', 'boolean'],
                'periods_per_week' => ['nullable', 'integer', 'min:0', 'max:40'],
                // bulk: assign multiple subjects to one class
                'subject_ids' => ['nullable', 'array'],
                'subject_ids.*' => ['integer'],
            ]);

            $this->assertClassroom($schoolId, (int) $data['classroom_id']);
            $this->assertYear($schoolId, (int) $data['academic_year_id']);

            $subjectIds = ! empty($data['subject_ids'])
                ? array_map('intval', $data['subject_ids'])
                : [(int) $data['subject_id']];

            $created = [];
            foreach ($subjectIds as $sid) {
                $this->assertSubject($schoolId, $sid);
                $row = ClassroomSubject::query()->updateOrCreate(
                    [
                        'classroom_id' => (int) $data['classroom_id'],
                        'subject_id' => $sid,
                        'academic_year_id' => (int) $data['academic_year_id'],
                    ],
                    [
                        'school_id' => $schoolId,
                        'is_compulsory' => $data['is_compulsory'] ?? true,
                        'periods_per_week' => $data['periods_per_week'] ?? null,
                    ]
                );
                $created[] = $row->load([
                    'classroom:id,name,code',
                    'subject:id,name,code,short_name',
                    'academicYear:id,name,is_current',
                ]);
            }

            return $this->success([
                'assignments' => $created,
                'assignment' => $created[0] ?? null,
            ], 'Class subject assignment saved', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function classAssignmentsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $row = ClassroomSubject::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'is_compulsory' => ['sometimes', 'boolean'],
                'periods_per_week' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:40'],
            ]);
            $row->fill($data);
            $row->save();

            return $this->success([
                'assignment' => $row->load([
                    'classroom:id,name,code',
                    'subject:id,name,code,short_name',
                    'academicYear:id,name,is_current',
                ]),
            ], 'Assignment updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function classAssignmentsDestroy(Request $request, int $id)
    {
        try {
            $row = ClassroomSubject::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $row->delete();

            return $this->success(['id' => $id], 'Assignment removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ─── Teacher allocation ───────────────────────────────────────

    public function teacherAllocationsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = TeacherSubjectAllocation::query()
                ->where('school_id', $schoolId)
                ->with([
                    'staff:id,first_name,last_name,employee_code,status',
                    'subject:id,name,code,short_name',
                    'classroom:id,name,code',
                    'academicYear:id,name,is_current',
                    'term:id,name',
                ]);

            if ($request->filled('staff_id')) {
                $q->where('staff_id', (int) $request->input('staff_id'));
            }
            if ($request->filled('classroom_id')) {
                $q->where('classroom_id', (int) $request->input('classroom_id'));
            }
            if ($request->filled('academic_year_id')) {
                $q->where('academic_year_id', (int) $request->input('academic_year_id'));
            }
            if ($request->filled('subject_id')) {
                $q->where('subject_id', (int) $request->input('subject_id'));
            }

            $rows = $q->orderByDesc('id')->get()->map(function (TeacherSubjectAllocation $a) {
                $arr = $a->toArray();
                $arr['staff_name'] = $a->staff?->full_name;

                return $arr;
            });

            return $this->success(['allocations' => $rows]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function teacherAllocationsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'staff_id' => ['required', 'integer'],
                'subject_id' => ['required', 'integer'],
                'classroom_id' => ['required', 'integer'],
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'periods_per_week' => ['nullable', 'integer', 'min:0', 'max:40'],
                'is_primary_teacher' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $this->assertStaff($schoolId, (int) $data['staff_id']);
            $this->assertSubject($schoolId, (int) $data['subject_id']);
            $this->assertClassroom($schoolId, (int) $data['classroom_id']);
            $this->assertYear($schoolId, (int) $data['academic_year_id']);
            if (! empty($data['term_id'])) {
                $this->assertTerm($schoolId, (int) $data['term_id']);
            }

            $row = TeacherSubjectAllocation::query()->updateOrCreate(
                [
                    'staff_id' => (int) $data['staff_id'],
                    'subject_id' => (int) $data['subject_id'],
                    'classroom_id' => (int) $data['classroom_id'],
                    'academic_year_id' => (int) $data['academic_year_id'],
                ],
                [
                    'school_id' => $schoolId,
                    'term_id' => $data['term_id'] ?? null,
                    'periods_per_week' => $data['periods_per_week'] ?? null,
                    'is_primary_teacher' => $data['is_primary_teacher'] ?? true,
                    'notes' => $data['notes'] ?? null,
                ]
            );

            $row->load([
                'staff:id,first_name,last_name,employee_code,status',
                'subject:id,name,code,short_name',
                'classroom:id,name,code',
                'academicYear:id,name,is_current',
                'term:id,name',
            ]);

            $payload = $row->toArray();
            $payload['staff_name'] = $row->staff?->full_name;

            return $this->success(['allocation' => $payload], 'Teacher allocation saved', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function teacherAllocationsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $row = TeacherSubjectAllocation::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'term_id' => ['sometimes', 'nullable', 'integer'],
                'periods_per_week' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:40'],
                'is_primary_teacher' => ['sometimes', 'boolean'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]);
            if (array_key_exists('term_id', $data) && $data['term_id']) {
                $this->assertTerm($schoolId, (int) $data['term_id']);
            }
            $row->fill($data);
            $row->save();
            $row->load([
                'staff:id,first_name,last_name,employee_code,status',
                'subject:id,name,code,short_name',
                'classroom:id,name,code',
                'academicYear:id,name,is_current',
                'term:id,name',
            ]);
            $payload = $row->toArray();
            $payload['staff_name'] = $row->staff?->full_name;

            return $this->success(['allocation' => $payload], 'Allocation updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function teacherAllocationsDestroy(Request $request, int $id)
    {
        try {
            $row = TeacherSubjectAllocation::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $row->delete();

            return $this->success(['id' => $id], 'Allocation removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function validateSubject(Request $request, int $schoolId, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'short_name' => ['nullable', 'string', 'max:40'],
            'code' => ['nullable', 'string', 'max:30'],
            'necta_code' => ['nullable', 'string', 'max:20'],
            'subject_category_id' => ['nullable', 'integer'],
            'education_stage' => ['nullable', Rule::in(self::STAGES)],
            'is_core' => ['nullable', 'boolean'],
            'is_compulsory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'weekly_periods' => ['nullable', 'integer', 'min:0', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        // Subjects should match a stage the school actually offers
        if (! empty($data['education_stage']) && $data['education_stage'] !== 'all') {
            $school = \App\Models\School::query()->find($schoolId);
            $offered = $school?->offeredStages() ?? [];
            if ($offered !== [] && ! in_array($data['education_stage'], $offered, true)) {
                throw new \InvalidArgumentException(
                    'That education stage is not enabled for this school. Update School → Education levels first.'
                );
            }
        }

        return $data;
    }

    protected function syncCombinationSubjects(int $schoolId, SubjectCombination $combo, array $data): void
    {
        $sync = [];
        if (! empty($data['subjects']) && is_array($data['subjects'])) {
            foreach ($data['subjects'] as $i => $item) {
                $sid = (int) ($item['subject_id'] ?? 0);
                if (! $sid) {
                    continue;
                }
                $this->assertSubject($schoolId, $sid);
                $sync[$sid] = [
                    'is_principal' => (bool) ($item['is_principal'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? $i + 1),
                ];
            }
        } elseif (! empty($data['subject_ids']) && is_array($data['subject_ids'])) {
            foreach ($data['subject_ids'] as $i => $sid) {
                $sid = (int) $sid;
                $this->assertSubject($schoolId, $sid);
                $sync[$sid] = [
                    'is_principal' => true,
                    'sort_order' => $i + 1,
                ];
            }
        }
        $combo->subjects()->sync($sync);
    }

    protected function transformCombination(SubjectCombination $c): array
    {
        return [
            'id' => $c->id,
            'school_id' => $c->school_id,
            'name' => $c->name,
            'code' => $c->code,
            'education_stage' => $c->education_stage,
            'description' => $c->description,
            'is_active' => $c->is_active,
            'subjects' => $c->subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'short_name' => $s->short_name,
                'is_principal' => (bool) ($s->pivot->is_principal ?? true),
                'sort_order' => (int) ($s->pivot->sort_order ?? 0),
            ])->values(),
            'subject_ids' => $c->subjects->pluck('id')->values(),
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }

    protected function assertCategory(int $schoolId, int $id): void
    {
        if (! SubjectCategory::query()->where('school_id', $schoolId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Subject category does not belong to this school.');
        }
    }

    protected function assertSubject(int $schoolId, int $id): void
    {
        if (! Subject::query()->where('school_id', $schoolId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Subject does not belong to this school.');
        }
    }

    protected function assertClassroom(int $schoolId, int $id): void
    {
        if (! Classroom::query()->where('school_id', $schoolId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Class does not belong to this school.');
        }
    }

    protected function assertYear(int $schoolId, int $id): void
    {
        if (! AcademicYear::query()->where('school_id', $schoolId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Academic year does not belong to this school.');
        }
    }

    protected function assertTerm(int $schoolId, int $id): void
    {
        if (! Term::query()->where('school_id', $schoolId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Term does not belong to this school.');
        }
    }

    protected function assertStaff(int $schoolId, int $id): void
    {
        if (! Staff::query()->where('school_id', $schoolId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Staff member does not belong to this school.');
        }
    }
}
