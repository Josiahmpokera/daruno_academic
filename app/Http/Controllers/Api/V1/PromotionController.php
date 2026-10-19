<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AssessmentResult;
use App\Models\AttendanceRecord;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\PromotionDecision;
use App\Models\PromotionRule;
use App\Models\PromotionRun;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TAMISEMI-style student promotions: rules, automatic/manual runs, repeat class, reports.
 */
class PromotionController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Overview ──────────────────────────────────────────────────

    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);

            return $this->success([
                'summary' => [
                    'active_rules' => PromotionRule::query()->where('school_id', $schoolId)->where('is_active', true)->count(),
                    'total_rules' => PromotionRule::query()->where('school_id', $schoolId)->count(),
                    'runs' => PromotionRun::query()->where('school_id', $schoolId)->count(),
                    'promoted' => (int) PromotionDecision::query()->where('school_id', $schoolId)->where('outcome', 'promoted')->count(),
                    'repeated' => (int) PromotionDecision::query()->where('school_id', $schoolId)->where('outcome', 'repeated')->count(),
                    'last_run_at' => optional(
                        PromotionRun::query()->where('school_id', $schoolId)->orderByDesc('run_at')->value('run_at')
                    )?->toIso8601String(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Rules ─────────────────────────────────────────────────────

    public function rulesIndex(Request $request)
    {
        try {
            $items = PromotionRule::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'educationLevel:id,name',
                    'fromClassroom:id,name',
                    'toClassroom:id,name',
                ])
                ->orderBy('priority')
                ->orderBy('name')
                ->get();

            return $this->success(['rules' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function rulesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'education_level_id' => ['nullable', 'integer'],
                'from_classroom_id' => ['nullable', 'integer'],
                'to_classroom_id' => ['nullable', 'integer'],
                'min_average_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'min_attendance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'priority' => ['nullable', 'integer', 'min:1', 'max:100'],
                'is_active' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string'],
            ]);

            $item = PromotionRule::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'min_average_percent' => $data['min_average_percent'] ?? 40,
                'min_attendance_percent' => $data['min_attendance_percent'] ?? 75,
                'priority' => $data['priority'] ?? 10,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success([
                'rule' => $item->load(['educationLevel', 'fromClassroom', 'toClassroom']),
            ], 'Promotion rule created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function rulesUpdate(Request $request, int $id)
    {
        try {
            $item = PromotionRule::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'education_level_id' => ['nullable', 'integer'],
                'from_classroom_id' => ['nullable', 'integer'],
                'to_classroom_id' => ['nullable', 'integer'],
                'min_average_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'min_attendance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'priority' => ['nullable', 'integer', 'min:1', 'max:100'],
                'is_active' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success([
                'rule' => $item->fresh()->load(['educationLevel', 'fromClassroom', 'toClassroom']),
            ], 'Rule updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function rulesDestroy(Request $request, int $id)
    {
        try {
            $item = PromotionRule::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $item->delete();

            return $this->success(['deleted' => true], 'Rule deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Preview candidates ────────────────────────────────────────

    public function candidates(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'from_academic_year_id' => ['required', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
            ]);

            $enrollments = Enrollment::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $data['from_academic_year_id'])
                ->where('status', 'active')
                ->when(! empty($data['classroom_id']), fn ($q) => $q->where('classroom_id', $data['classroom_id']))
                ->with([
                    'student:id,first_name,middle_name,last_name,admission_number,status,gender',
                    'classroom:id,name,education_level_id',
                ])
                ->get();

            $rules = PromotionRule::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->orderBy('priority')
                ->get();

            $rows = $enrollments->map(function (Enrollment $enr) use ($schoolId, $data, $rules) {
                $avg = $this->studentAveragePercent($schoolId, $enr->student_id, (int) $data['from_academic_year_id']);
                $att = $this->studentAttendancePercent($schoolId, $enr->student_id, (int) $data['from_academic_year_id']);
                $rule = $this->matchRule($rules, $enr);
                $eligible = $rule
                    ? ($avg >= (float) $rule->min_average_percent && $att >= (float) $rule->min_attendance_percent)
                    : ($avg >= 40 && $att >= 75);
                $toClass = $rule?->to_classroom_id
                    ? Classroom::query()->where('school_id', $schoolId)->find($rule->to_classroom_id)
                    : $this->nextClassroom($schoolId, $enr->classroom);

                return [
                    'student_id' => $enr->student_id,
                    'enrollment_id' => $enr->id,
                    'student' => [
                        'id' => $enr->student?->id,
                        'full_name' => $enr->student?->full_name,
                        'admission_number' => $enr->student?->admission_number,
                        'gender' => $enr->student?->gender,
                        'status' => $enr->student?->status,
                    ],
                    'from_classroom' => $enr->classroom ? [
                        'id' => $enr->classroom->id,
                        'name' => $enr->classroom->name,
                    ] : null,
                    'suggested_to_classroom' => $toClass ? [
                        'id' => $toClass->id,
                        'name' => $toClass->name,
                    ] : null,
                    'average_percent' => $avg,
                    'attendance_percent' => $att,
                    'eligible' => $eligible,
                    'rule' => $rule ? [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'min_average_percent' => (float) $rule->min_average_percent,
                        'min_attendance_percent' => (float) $rule->min_attendance_percent,
                    ] : null,
                    'suggested_outcome' => $eligible ? 'promoted' : 'repeated',
                ];
            })->values();

            return $this->success([
                'candidates' => $rows,
                'summary' => [
                    'total' => $rows->count(),
                    'eligible' => $rows->where('eligible', true)->count(),
                    'not_eligible' => $rows->where('eligible', false)->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Run promotions ────────────────────────────────────────────

    /**
     * Automatic: evaluate rules for a class/year and apply outcomes.
     */
    public function runAutomatic(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'from_academic_year_id' => ['required', 'integer'],
                'to_academic_year_id' => ['required', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'title' => ['nullable', 'string', 'max:160'],
                'dry_run' => ['nullable', 'boolean'],
            ]);

            if ((int) $data['from_academic_year_id'] === (int) $data['to_academic_year_id']) {
                return $this->error('Source and target academic years must differ.', 422);
            }

            AcademicYear::query()->where('school_id', $schoolId)->findOrFail($data['from_academic_year_id']);
            AcademicYear::query()->where('school_id', $schoolId)->findOrFail($data['to_academic_year_id']);

            $request->merge([
                'from_academic_year_id' => $data['from_academic_year_id'],
                'classroom_id' => $data['classroom_id'] ?? null,
            ]);
            $preview = $this->candidates($request);
            $previewData = $preview->getData(true);
            if (! ($previewData['success'] ?? false)) {
                return $preview;
            }
            $candidates = collect($previewData['data']['candidates'] ?? []);

            if ($data['dry_run'] ?? false) {
                return $this->success([
                    'dry_run' => true,
                    'candidates' => $candidates,
                    'summary' => $previewData['data']['summary'] ?? [],
                ]);
            }

            $decisions = $candidates->map(function ($c) {
                $promote = (bool) ($c['eligible'] ?? false);

                return [
                    'student_id' => $c['student_id'],
                    'enrollment_id' => $c['enrollment_id'],
                    'from_classroom_id' => $c['from_classroom']['id'] ?? null,
                    'to_classroom_id' => $promote
                        ? ($c['suggested_to_classroom']['id'] ?? null)
                        : ($c['from_classroom']['id'] ?? null),
                    'outcome' => $promote ? 'promoted' : 'repeated',
                    'average_percent' => $c['average_percent'] ?? null,
                    'attendance_percent' => $c['attendance_percent'] ?? null,
                    'promotion_rule_id' => $c['rule']['id'] ?? null,
                    'reason' => $promote
                        ? 'Met automatic promotion criteria'
                        : 'Did not meet average/attendance thresholds',
                ];
            })->all();

            $run = $this->executeRun($request, [
                'mode' => 'automatic',
                'from_academic_year_id' => $data['from_academic_year_id'],
                'to_academic_year_id' => $data['to_academic_year_id'],
                'from_classroom_id' => $data['classroom_id'] ?? null,
                'title' => $data['title'] ?? 'Automatic promotion',
                'decisions' => $decisions,
            ]);

            return $this->success(['run' => $run], 'Automatic promotion completed', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Manual promote selected students to a target class.
     */
    public function runManual(Request $request)
    {
        try {
            $data = $request->validate([
                'from_academic_year_id' => ['required', 'integer'],
                'to_academic_year_id' => ['required', 'integer'],
                'to_classroom_id' => ['required', 'integer'],
                'student_ids' => ['required', 'array', 'min:1', 'max:200'],
                'student_ids.*' => ['integer'],
                'title' => ['nullable', 'string', 'max:160'],
                'reason' => ['nullable', 'string', 'max:255'],
            ]);

            $schoolId = $this->schoolId($request);
            Classroom::query()->where('school_id', $schoolId)->findOrFail($data['to_classroom_id']);

            $decisions = [];
            foreach ($data['student_ids'] as $studentId) {
                $enr = Enrollment::query()
                    ->where('school_id', $schoolId)
                    ->where('student_id', $studentId)
                    ->where('academic_year_id', $data['from_academic_year_id'])
                    ->where('status', 'active')
                    ->first();
                if (! $enr) {
                    continue;
                }
                $avg = $this->studentAveragePercent($schoolId, $studentId, (int) $data['from_academic_year_id']);
                $att = $this->studentAttendancePercent($schoolId, $studentId, (int) $data['from_academic_year_id']);
                $decisions[] = [
                    'student_id' => $studentId,
                    'enrollment_id' => $enr->id,
                    'from_classroom_id' => $enr->classroom_id,
                    'to_classroom_id' => $data['to_classroom_id'],
                    'outcome' => 'promoted',
                    'average_percent' => $avg,
                    'attendance_percent' => $att,
                    'promotion_rule_id' => null,
                    'reason' => $data['reason'] ?? 'Manual promotion override',
                ];
            }

            if ($decisions === []) {
                return $this->error('No active enrollments found for the selected students in the source year.', 422);
            }

            $run = $this->executeRun($request, [
                'mode' => 'manual',
                'from_academic_year_id' => $data['from_academic_year_id'],
                'to_academic_year_id' => $data['to_academic_year_id'],
                'from_classroom_id' => null,
                'title' => $data['title'] ?? 'Manual promotion',
                'decisions' => $decisions,
            ]);

            return $this->success(['run' => $run], 'Manual promotion completed', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Repeat class for selected students (new enrollment in same class for target year).
     */
    public function runRepeat(Request $request)
    {
        try {
            $data = $request->validate([
                'from_academic_year_id' => ['required', 'integer'],
                'to_academic_year_id' => ['required', 'integer'],
                'student_ids' => ['required', 'array', 'min:1', 'max:200'],
                'student_ids.*' => ['integer'],
                'title' => ['nullable', 'string', 'max:160'],
                'reason' => ['nullable', 'string', 'max:255'],
            ]);

            $schoolId = $this->schoolId($request);
            $decisions = [];
            foreach ($data['student_ids'] as $studentId) {
                $enr = Enrollment::query()
                    ->where('school_id', $schoolId)
                    ->where('student_id', $studentId)
                    ->where('academic_year_id', $data['from_academic_year_id'])
                    ->where('status', 'active')
                    ->first();
                if (! $enr) {
                    continue;
                }
                $avg = $this->studentAveragePercent($schoolId, $studentId, (int) $data['from_academic_year_id']);
                $att = $this->studentAttendancePercent($schoolId, $studentId, (int) $data['from_academic_year_id']);
                $decisions[] = [
                    'student_id' => $studentId,
                    'enrollment_id' => $enr->id,
                    'from_classroom_id' => $enr->classroom_id,
                    'to_classroom_id' => $enr->classroom_id,
                    'outcome' => 'repeated',
                    'average_percent' => $avg,
                    'attendance_percent' => $att,
                    'promotion_rule_id' => null,
                    'reason' => $data['reason'] ?? 'Repeat class (held back)',
                ];
            }

            if ($decisions === []) {
                return $this->error('No active enrollments found for the selected students.', 422);
            }

            $run = $this->executeRun($request, [
                'mode' => 'repeat',
                'from_academic_year_id' => $data['from_academic_year_id'],
                'to_academic_year_id' => $data['to_academic_year_id'],
                'from_classroom_id' => null,
                'title' => $data['title'] ?? 'Repeat class',
                'decisions' => $decisions,
            ]);

            return $this->success(['run' => $run], 'Repeat class recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Runs & reports ────────────────────────────────────────────

    public function runsIndex(Request $request)
    {
        try {
            $items = PromotionRun::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['fromYear:id,name', 'toYear:id,name', 'fromClassroom:id,name'])
                ->orderByDesc('id')
                ->limit(100)
                ->get();

            return $this->success(['runs' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function runsShow(Request $request, int $id)
    {
        try {
            $run = PromotionRun::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'fromYear:id,name',
                    'toYear:id,name',
                    'fromClassroom:id,name',
                    'decisions.student:id,first_name,last_name,admission_number',
                    'decisions.fromClassroom:id,name',
                    'decisions.toClassroom:id,name',
                    'decisions.rule:id,name',
                ])
                ->findOrFail($id);

            return $this->success(['run' => $run]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function reports(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $yearId = $request->integer('academic_year_id') ?: null;

            $byOutcome = PromotionDecision::query()
                ->where('school_id', $schoolId)
                ->when($yearId, function ($q) use ($yearId) {
                    $q->whereHas('run', fn ($r) => $r->where('from_academic_year_id', $yearId));
                })
                ->selectRaw('outcome, count(*) as total')
                ->groupBy('outcome')
                ->get();

            $byClass = PromotionDecision::query()
                ->where('promotion_decisions.school_id', $schoolId)
                ->when($yearId, function ($q) use ($yearId) {
                    $q->whereHas('run', fn ($r) => $r->where('from_academic_year_id', $yearId));
                })
                ->leftJoin('classrooms', 'classrooms.id', '=', 'promotion_decisions.from_classroom_id')
                ->selectRaw("COALESCE(classrooms.name, 'Unknown') as classroom,
                    sum(case when outcome='promoted' then 1 else 0 end) as promoted,
                    sum(case when outcome='repeated' then 1 else 0 end) as repeated,
                    sum(case when outcome='skipped' then 1 else 0 end) as skipped,
                    count(*) as total")
                ->groupBy('classrooms.id', 'classrooms.name')
                ->orderBy('classroom')
                ->get();

            $recent = PromotionRun::query()
                ->where('school_id', $schoolId)
                ->with(['fromYear:id,name', 'toYear:id,name'])
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            return $this->success([
                'by_outcome' => $byOutcome,
                'by_class' => $byClass,
                'recent_runs' => $recent,
                'totals' => [
                    'promoted' => (int) ($byOutcome->firstWhere('outcome', 'promoted')?->total ?? 0),
                    'repeated' => (int) ($byOutcome->firstWhere('outcome', 'repeated')?->total ?? 0),
                    'skipped' => (int) ($byOutcome->firstWhere('outcome', 'skipped')?->total ?? 0),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Core execution ────────────────────────────────────────────

    protected function executeRun(Request $request, array $payload): array
    {
        $schoolId = $this->schoolId($request);

        return DB::transaction(function () use ($schoolId, $request, $payload) {
            $run = PromotionRun::query()->create([
                'school_id' => $schoolId,
                'from_academic_year_id' => $payload['from_academic_year_id'],
                'to_academic_year_id' => $payload['to_academic_year_id'],
                'from_classroom_id' => $payload['from_classroom_id'] ?? null,
                'mode' => $payload['mode'],
                'status' => 'completed',
                'title' => $payload['title'] ?? null,
                'run_at' => now(),
                'created_by' => $request->user()?->id,
            ]);

            $promoted = 0;
            $repeated = 0;
            $skipped = 0;

            foreach ($payload['decisions'] as $d) {
                $outcome = $d['outcome'];
                $toClassroomId = $d['to_classroom_id'] ?? null;
                $newEnrollmentId = null;

                if (in_array($outcome, ['promoted', 'repeated'], true) && $toClassroomId) {
                    // Close source enrollment
                    if (! empty($d['enrollment_id'])) {
                        Enrollment::query()
                            ->where('school_id', $schoolId)
                            ->where('id', $d['enrollment_id'])
                            ->update(['status' => $outcome === 'promoted' ? 'promoted' : 'repeated']);
                    }

                    // Avoid duplicate target enrollment
                    $existing = Enrollment::query()
                        ->where('school_id', $schoolId)
                        ->where('student_id', $d['student_id'])
                        ->where('academic_year_id', $payload['to_academic_year_id'])
                        ->where('classroom_id', $toClassroomId)
                        ->first();

                    if ($existing) {
                        $existing->status = 'active';
                        $existing->save();
                        $newEnrollmentId = $existing->id;
                    } else {
                        $new = Enrollment::query()->create([
                            'school_id' => $schoolId,
                            'student_id' => $d['student_id'],
                            'classroom_id' => $toClassroomId,
                            'academic_year_id' => $payload['to_academic_year_id'],
                            'term_id' => null,
                            'enrolled_at' => now()->toDateString(),
                            'status' => 'active',
                        ]);
                        $newEnrollmentId = $new->id;
                    }

                    // Keep student active
                    Student::query()
                        ->where('school_id', $schoolId)
                        ->where('id', $d['student_id'])
                        ->update(['status' => 'active']);
                }

                if ($outcome === 'promoted') {
                    $promoted++;
                } elseif ($outcome === 'repeated') {
                    $repeated++;
                } else {
                    $skipped++;
                }

                PromotionDecision::query()->create([
                    'school_id' => $schoolId,
                    'promotion_run_id' => $run->id,
                    'student_id' => $d['student_id'],
                    'from_classroom_id' => $d['from_classroom_id'] ?? null,
                    'to_classroom_id' => $toClassroomId,
                    'from_enrollment_id' => $d['enrollment_id'] ?? null,
                    'to_enrollment_id' => $newEnrollmentId,
                    'promotion_rule_id' => $d['promotion_rule_id'] ?? null,
                    'outcome' => $outcome,
                    'average_percent' => $d['average_percent'] ?? null,
                    'attendance_percent' => $d['attendance_percent'] ?? null,
                    'reason' => $d['reason'] ?? null,
                ]);
            }

            $run->promoted_count = $promoted;
            $run->repeated_count = $repeated;
            $run->skipped_count = $skipped;
            $run->save();

            return $run->fresh()->load([
                'fromYear:id,name',
                'toYear:id,name',
                'fromClassroom:id,name',
                'decisions.student:id,first_name,last_name,admission_number',
                'decisions.fromClassroom:id,name',
                'decisions.toClassroom:id,name',
            ])->toArray();
        });
    }

    /**
     * Average mark as a percent for the academic year.
     * assessment_results stores raw `score`; percent = score / assessments.max_score * 100.
     */
    protected function studentAveragePercent(int $schoolId, int $studentId, int $yearId): float
    {
        if (! class_exists(AssessmentResult::class)) {
            return 0.0;
        }

        $rows = AssessmentResult::query()
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->where('assessment_results.student_id', $studentId)
            ->where('assessments.school_id', $schoolId)
            ->where('assessments.academic_year_id', $yearId)
            ->whereNotNull('assessment_results.score')
            ->selectRaw(
                'assessment_results.score as score, COALESCE(NULLIF(assessments.max_score, 0), 100) as max_score'
            )
            ->get();

        if ($rows->isEmpty()) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            $max = (float) $row->max_score;
            $sum += ((float) $row->score / $max) * 100;
        }

        return round($sum / $rows->count(), 2);
    }

    protected function studentAttendancePercent(int $schoolId, int $studentId, int $yearId): float
    {
        // Prefer date range of academic year if available
        $year = AcademicYear::query()->find($yearId);
        $q = AttendanceRecord::query()
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId);

        if ($year && $year->starts_at && $year->ends_at) {
            $q->whereDate('date', '>=', $year->starts_at)
                ->whereDate('date', '<=', $year->ends_at);
        }

        $total = (clone $q)->count();
        if ($total === 0) {
            return 100.0; // no records → do not block promotion
        }
        $present = (clone $q)->whereIn('status', ['present', 'late', 'Present', 'Late', 'P', 'L'])->count();

        return round(($present / $total) * 100, 2);
    }

    protected function matchRule($rules, Enrollment $enr): ?PromotionRule
    {
        foreach ($rules as $rule) {
            if ($rule->from_classroom_id && (int) $rule->from_classroom_id === (int) $enr->classroom_id) {
                return $rule;
            }
        }
        foreach ($rules as $rule) {
            if ($rule->education_level_id
                && $enr->classroom
                && (int) $rule->education_level_id === (int) $enr->classroom->education_level_id
                && ! $rule->from_classroom_id) {
                return $rule;
            }
        }
        foreach ($rules as $rule) {
            if (! $rule->from_classroom_id && ! $rule->education_level_id) {
                return $rule;
            }
        }

        return null;
    }

    protected function nextClassroom(int $schoolId, ?Classroom $from): ?Classroom
    {
        if (! $from) {
            return null;
        }

        $siblings = Classroom::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->when($from->education_level_id, fn ($q) => $q->where('education_level_id', $from->education_level_id))
            ->orderBy('name')
            ->get();

        $idx = $siblings->search(fn ($c) => (int) $c->id === (int) $from->id);
        if ($idx === false) {
            return null;
        }
        // Next named class in same level; if last, stay (finalist)
        return $siblings->get($idx + 1);
    }
}
