<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\GradingSystem;
use App\Support\NectaGrading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExaminationController extends Controller
{
    use ApiResponds, ResolvesSchool;

    /** TAMISEMI / NECTA-oriented assessment type catalogue */
    public const TYPES = [
        ['value' => 'continuous', 'label' => 'Continuous Assessment (CA)', 'group' => 'formative'],
        ['value' => 'homework', 'label' => 'Homework / assignment', 'group' => 'formative'],
        ['value' => 'practical', 'label' => 'Practical assessment', 'group' => 'formative'],
        ['value' => 'weekly', 'label' => 'Weekly / unit test', 'group' => 'formative'],
        ['value' => 'midterm', 'label' => 'Mid-term examination', 'group' => 'summative'],
        ['value' => 'terminal', 'label' => 'Terminal examination', 'group' => 'summative'],
        ['value' => 'mock', 'label' => 'Mock examination', 'group' => 'summative'],
        ['value' => 'final', 'label' => 'Final / annual examination', 'group' => 'summative'],
        ['value' => 'project', 'label' => 'Project / portfolio', 'group' => 'formative'],
    ];

    public function typesIndex(Request $request)
    {
        $grading = [
            'scale' => 'NECTA-oriented (CSEE/ACSEE style bands)',
            'bands' => [
                ['grade' => 'A', 'min_percent' => 75, 'points' => 1, 'remark' => 'Excellent'],
                ['grade' => 'B', 'min_percent' => 65, 'points' => 2, 'remark' => 'Very Good'],
                ['grade' => 'C', 'min_percent' => 45, 'points' => 3, 'remark' => 'Good'],
                ['grade' => 'D', 'min_percent' => 30, 'points' => 4, 'remark' => 'Satisfactory'],
                ['grade' => 'F', 'min_percent' => 0, 'points' => 5, 'remark' => 'Fail'],
            ],
            'division' => 'From average grade points (lower is better): I–IV, 0',
            'pass_mark' => 30,
            'grading_system_id' => null,
        ];

        try {
            $schoolId = $this->schoolId($request);
            $system = GradingSystem::query()
                ->where('school_id', $schoolId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->with('bands')
                ->first();
            if ($system && $system->bands->isNotEmpty()) {
                $grading = [
                    'scale' => $system->name,
                    'bands' => $system->bands->map(fn ($b) => [
                        'grade' => $b->grade,
                        'min_percent' => (float) $b->min_percent,
                        'points' => (int) $b->points,
                        'remark' => $b->remark,
                    ])->values()->all(),
                    'division' => 'From average grade points (lower is better): I–IV, 0',
                    'pass_mark' => (float) $system->pass_mark,
                    'grading_system_id' => $system->id,
                ];
            }
        } catch (Throwable $e) {
            // fall back to built-in NECTA bands
        }

        return $this->success([
            'types' => self::TYPES,
            'grading' => $grading,
        ]);
    }

    public function assessmentsIndex(Request $request)
    {
        try {
            $query = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['subject', 'classroom', 'academicYear', 'term'])
                ->withCount('results')
                ->orderByDesc('date')
                ->orderByDesc('id');

            if ($request->integer('classroom_id')) {
                $query->where('classroom_id', $request->integer('classroom_id'));
            }
            if ($request->integer('term_id')) {
                $query->where('term_id', $request->integer('term_id'));
            }
            if ($request->integer('academic_year_id')) {
                $query->where('academic_year_id', $request->integer('academic_year_id'));
            }
            if ($request->integer('subject_id')) {
                $query->where('subject_id', $request->integer('subject_id'));
            }
            if ($request->filled('type')) {
                $query->where('type', $request->string('type')->toString());
            }
            if ($request->filled('published')) {
                $query->where('is_published', filter_var($request->input('published'), FILTER_VALIDATE_BOOLEAN));
            }

            return $this->success([
                'assessments' => $query->get(),
                'types' => self::TYPES,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function assessmentsStore(Request $request)
    {
        try {
            $allowedTypes = collect(self::TYPES)->pluck('value')->all();

            $data = $request->validate([
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'subject_id' => ['nullable', 'integer'],
                'name' => ['required', 'string', 'max:150'],
                'type' => ['required', 'string', 'max:30', 'in:'.implode(',', $allowedTypes)],
                'date' => ['nullable', 'date'],
                'max_score' => ['nullable', 'numeric', 'min:1'],
                'weight' => ['nullable', 'numeric', 'min:0'],
            ]);

            $assessment = Assessment::query()->create([
                'school_id' => $this->schoolId($request),
                'max_score' => $data['max_score'] ?? 100,
                'weight' => $data['weight'] ?? 1,
                'is_published' => false,
                'is_locked' => false,
                ...collect($data)->except(['max_score', 'weight'])->all(),
            ]);

            return $this->success([
                'assessment' => $assessment->load(['subject', 'classroom', 'academicYear', 'term']),
            ], 'Assessment created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function assessmentsUpdate(Request $request, int $id)
    {
        try {
            $assessment = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($assessment->is_locked) {
                return $this->error('Assessment is locked. Unlock before editing.', 422);
            }

            $allowedTypes = collect(self::TYPES)->pluck('value')->all();

            $data = $request->validate([
                'academic_year_id' => ['sometimes', 'integer'],
                'term_id' => ['sometimes', 'nullable', 'integer'],
                'classroom_id' => ['sometimes', 'nullable', 'integer'],
                'subject_id' => ['sometimes', 'nullable', 'integer'],
                'name' => ['sometimes', 'string', 'max:150'],
                'type' => ['sometimes', 'string', 'max:30', 'in:'.implode(',', $allowedTypes)],
                'date' => ['sometimes', 'nullable', 'date'],
                'max_score' => ['sometimes', 'numeric', 'min:1'],
                'weight' => ['sometimes', 'numeric', 'min:0'],
            ]);

            $assessment->fill($data);
            $assessment->save();

            return $this->success([
                'assessment' => $assessment->load(['subject', 'classroom', 'academicYear', 'term']),
            ], 'Assessment updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function assessmentsDestroy(Request $request, int $id)
    {
        try {
            $assessment = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($assessment->is_locked || $assessment->is_published) {
                return $this->error('Cannot delete a published or locked assessment. Unlock/unpublish first.', 422);
            }

            AssessmentResult::query()->where('assessment_id', $assessment->id)->delete();
            $assessment->delete();

            return $this->success(['id' => $id], 'Assessment deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function lock(Request $request, int $assessmentId)
    {
        try {
            $assessment = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($assessmentId);
            $assessment->is_locked = true;
            $assessment->save();

            return $this->success(['assessment' => $assessment], 'Results locked');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function unlock(Request $request, int $assessmentId)
    {
        try {
            $assessment = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($assessmentId);
            $assessment->is_locked = false;
            $assessment->save();

            return $this->success(['assessment' => $assessment], 'Results unlocked');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function unpublish(Request $request, int $assessmentId)
    {
        try {
            $assessment = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($assessmentId);
            if ($assessment->is_locked) {
                return $this->error('Unlock before unpublishing.', 422);
            }
            $assessment->is_published = false;
            $assessment->save();

            return $this->success(['assessment' => $assessment], 'Assessment unpublished');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function resultsIndex(Request $request, int $assessmentId)
    {
        try {
            $schoolId = $this->schoolId($request);
            $assessment = Assessment::query()
                ->where('school_id', $schoolId)
                ->with(['subject', 'classroom'])
                ->findOrFail($assessmentId);

            $results = AssessmentResult::query()
                ->where('assessment_id', $assessment->id)
                ->with('student')
                ->get();

            return $this->success([
                'assessment' => $assessment,
                'results' => $results,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /**
     * Bulk enter marks: results: [{ student_id, score, remark? }]
     */
    public function resultsStore(Request $request, int $assessmentId)
    {
        try {
            $schoolId = $this->schoolId($request);
            $assessment = Assessment::query()
                ->where('school_id', $schoolId)
                ->findOrFail($assessmentId);

            if ($assessment->is_locked) {
                return $this->error('This assessment is locked. Unlock before editing marks.', 422);
            }

            $data = $request->validate([
                'results' => ['required', 'array', 'min:1'],
                'results.*.student_id' => ['required', 'integer'],
                'results.*.score' => ['required', 'numeric', 'min:0'],
                'results.*.remark' => ['nullable', 'string', 'max:255'],
            ]);

            $saved = DB::transaction(function () use ($assessment, $data, $schoolId) {
                $out = [];
                $max = (float) $assessment->max_score;

                foreach ($data['results'] as $row) {
                    Student::query()->where('school_id', $schoolId)->findOrFail($row['student_id']);
                    $score = min((float) $row['score'], $max);
                    $percent = $max > 0 ? ($score / $max) * 100 : 0;
                    $graded = NectaGrading::gradeFromPercent($percent);

                    $result = AssessmentResult::query()->updateOrCreate(
                        [
                            'assessment_id' => $assessment->id,
                            'student_id' => $row['student_id'],
                        ],
                        [
                            'score' => $score,
                            'grade' => $graded['grade'],
                            'remark' => $row['remark'] ?? $graded['remark'],
                        ]
                    );
                    $out[] = $result;
                }

                return $out;
            });

            return $this->success([
                'assessment_id' => $assessment->id,
                'saved' => count($saved),
                'results' => $saved,
            ], 'Results saved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function publish(Request $request, int $assessmentId)
    {
        try {
            $assessment = Assessment::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount('results')
                ->findOrFail($assessmentId);

            if ((int) $assessment->results_count === 0) {
                return $this->error('Enter at least one mark before publishing.', 422);
            }

            $assessment->is_published = true;
            $assessment->save();

            return $this->success(['assessment' => $assessment], 'Assessment published');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * NECTA-oriented report card payload (JSON) for a student/term.
     */
    public function reportCard(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer'],
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
            ]);

            $school = $this->school($request);
            $student = Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);

            $enrollment = Enrollment::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $data['academic_year_id'])
                ->when(! empty($data['classroom_id']), fn ($q) => $q->where('classroom_id', $data['classroom_id']))
                ->with('classroom', 'academicYear', 'term')
                ->latest('id')
                ->first();

            $assessments = Assessment::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $data['academic_year_id'])
                ->when(! empty($data['term_id']), fn ($q) => $q->where('term_id', $data['term_id']))
                ->when($enrollment?->classroom_id, fn ($q) => $q->where(function ($q) use ($enrollment) {
                    $q->whereNull('classroom_id')->orWhere('classroom_id', $enrollment->classroom_id);
                }))
                ->where('is_published', true)
                ->with(['subject', 'results' => fn ($q) => $q->where('student_id', $student->id)])
                ->get();

            $subjectRows = [];
            $pointSum = 0;
            $subjectCount = 0;

            foreach ($assessments->groupBy('subject_id') as $subjectId => $group) {
                $subject = $group->first()->subject;
                $scores = [];
                $weighted = 0;
                $weightSum = 0;

                foreach ($group as $assessment) {
                    $result = $assessment->results->first();
                    if (! $result || $result->score === null) {
                        continue;
                    }
                    $percent = ((float) $result->score / (float) $assessment->max_score) * 100;
                    $scores[] = [
                        'assessment' => $assessment->name,
                        'type' => $assessment->type,
                        'score' => (float) $result->score,
                        'max_score' => (float) $assessment->max_score,
                        'percent' => round($percent, 2),
                        'grade' => $result->grade,
                    ];
                    $w = (float) $assessment->weight ?: 1;
                    $weighted += $percent * $w;
                    $weightSum += $w;
                }

                if ($weightSum <= 0) {
                    continue;
                }

                $avgPercent = $weighted / $weightSum;
                $graded = NectaGrading::gradeFromPercent($avgPercent);
                $pointSum += $graded['points'];
                $subjectCount++;

                $subjectRows[] = [
                    'subject_id' => $subjectId,
                    'subject_name' => $subject?->name ?? 'Subject',
                    'subject_code' => $subject?->code,
                    'average_percent' => round($avgPercent, 2),
                    'grade' => $graded['grade'],
                    'points' => $graded['points'],
                    'remark' => $graded['remark'],
                    'assessments' => $scores,
                ];
            }

            $avgPoints = $subjectCount > 0 ? $pointSum / $subjectCount : 5;
            $division = NectaGrading::divisionFromAveragePoints($avgPoints);

            // Class position (by average points — lower better)
            $classmates = [];
            if ($enrollment) {
                $classStudentIds = Enrollment::query()
                    ->where('school_id', $schoolId)
                    ->where('classroom_id', $enrollment->classroom_id)
                    ->where('academic_year_id', $data['academic_year_id'])
                    ->where('status', 'active')
                    ->pluck('student_id');

                foreach ($classStudentIds as $sid) {
                    $classmates[] = [
                        'student_id' => $sid,
                        'avg_points' => $this->studentAveragePoints(
                            $schoolId,
                            (int) $sid,
                            (int) $data['academic_year_id'],
                            $data['term_id'] ?? null,
                            $enrollment->classroom_id
                        ),
                    ];
                }
                usort($classmates, fn ($a, $b) => $a['avg_points'] <=> $b['avg_points']);
            }

            $position = null;
            $classSize = count($classmates);
            foreach ($classmates as $i => $row) {
                if ((int) $row['student_id'] === (int) $student->id) {
                    $position = $i + 1;
                    break;
                }
            }

            $report = [
                'format' => 'NECTA-oriented',
                'generated_at' => now()->toIso8601String(),
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'code' => $school->code,
                ],
                'student' => [
                    'id' => $student->id,
                    'admission_number' => $student->admission_number,
                    'full_name' => $student->full_name,
                    'gender' => $student->gender,
                    'date_of_birth' => optional($student->date_of_birth)?->toDateString(),
                ],
                'class' => [
                    'id' => $enrollment?->classroom_id,
                    'name' => $enrollment?->classroom?->name,
                    'academic_year' => $enrollment?->academicYear?->name,
                    'term' => $enrollment?->term?->name,
                ],
                'subjects' => $subjectRows,
                'summary' => [
                    'subjects_count' => $subjectCount,
                    'average_points' => round($avgPoints, 2),
                    'division' => $division,
                    'class_position' => $position,
                    'class_size' => $classSize,
                ],
                'head_teacher_remark' => $this->defaultHeadRemark($division),
                'export' => [
                    'json' => true,
                    'print_html' => true,
                ],
            ];

            return $this->success(['report_card' => $report]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Printable HTML export (NECTA-style layout).
     */
    public function reportCardHtml(Request $request)
    {
        try {
            $json = $this->reportCard($request);
            $payload = $json->getData(true);

            if (! ($payload['success'] ?? false)) {
                return $json;
            }

            $r = $payload['data']['report_card'];
            $html = view('reports.necta_report_card', ['report' => $r])->render();

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Class ranking / merit list (NECTA points — lower average is better).
     */
    public function ranking(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'classroom_id' => ['required', 'integer'],
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
            ]);

            $studentIds = Enrollment::query()
                ->where('school_id', $schoolId)
                ->where('classroom_id', $data['classroom_id'])
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('status', 'active')
                ->pluck('student_id');

            $rows = [];
            foreach ($studentIds as $sid) {
                $student = Student::query()->where('school_id', $schoolId)->find($sid);
                if (! $student) {
                    continue;
                }
                $avg = $this->studentAveragePoints(
                    $schoolId,
                    (int) $sid,
                    (int) $data['academic_year_id'],
                    $data['term_id'] ?? null,
                    (int) $data['classroom_id']
                );
                $division = NectaGrading::divisionFromAveragePoints($avg);
                $rows[] = [
                    'student_id' => (int) $sid,
                    'admission_number' => $student->admission_number,
                    'full_name' => $student->full_name,
                    'gender' => $student->gender,
                    'average_points' => round($avg, 2),
                    'division' => $division,
                ];
            }

            usort($rows, fn ($a, $b) => $a['average_points'] <=> $b['average_points']);
            foreach ($rows as $i => &$row) {
                $row['position'] = $i + 1;
            }
            unset($row);

            $divisions = collect($rows)->groupBy('division')->map->count();

            return $this->success([
                'ranking' => $rows,
                'class_size' => count($rows),
                'division_summary' => $divisions,
                'merit_list' => array_slice($rows, 0, 10),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Subject performance analysis for a class/term.
     */
    public function subjectAnalysis(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'classroom_id' => ['required', 'integer'],
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'subject_id' => ['nullable', 'integer'],
            ]);

            $assessments = Assessment::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $data['academic_year_id'])
                ->when(! empty($data['term_id']), fn ($q) => $q->where('term_id', $data['term_id']))
                ->when(! empty($data['subject_id']), fn ($q) => $q->where('subject_id', $data['subject_id']))
                ->where(function ($q) use ($data) {
                    $q->whereNull('classroom_id')->orWhere('classroom_id', $data['classroom_id']);
                })
                ->where('is_published', true)
                ->with(['subject', 'results.student'])
                ->get();

            $bySubject = [];
            foreach ($assessments->groupBy('subject_id') as $subjectId => $group) {
                $subject = $group->first()->subject;
                $percents = [];
                $gradeCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

                foreach ($group as $assessment) {
                    foreach ($assessment->results as $result) {
                        if ($result->score === null) {
                            continue;
                        }
                        $percent = ((float) $result->score / (float) $assessment->max_score) * 100;
                        $percents[] = $percent;
                        $g = $result->grade ?: NectaGrading::gradeFromPercent($percent)['grade'];
                        if (isset($gradeCounts[$g])) {
                            $gradeCounts[$g]++;
                        }
                    }
                }

                if (! count($percents)) {
                    continue;
                }

                $mean = array_sum($percents) / count($percents);
                $pass = count(array_filter($percents, fn ($p) => $p >= 30));

                $bySubject[] = [
                    'subject_id' => $subjectId,
                    'subject_name' => $subject?->name ?? 'Subject',
                    'subject_code' => $subject?->code,
                    'entries' => count($percents),
                    'mean_percent' => round($mean, 2),
                    'pass_rate' => round(($pass / count($percents)) * 100, 1),
                    'grade_distribution' => $gradeCounts,
                    'mean_grade' => NectaGrading::gradeFromPercent($mean)['grade'],
                ];
            }

            usort($bySubject, fn ($a, $b) => $b['mean_percent'] <=> $a['mean_percent']);

            return $this->success([
                'subjects' => $bySubject,
                'assessment_count' => $assessments->count(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    protected function studentAveragePoints(
        int $schoolId,
        int $studentId,
        int $yearId,
        ?int $termId,
        ?int $classroomId
    ): float {
        $assessments = Assessment::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $yearId)
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->when($classroomId, fn ($q) => $q->where(function ($q) use ($classroomId) {
                $q->whereNull('classroom_id')->orWhere('classroom_id', $classroomId);
            }))
            ->where('is_published', true)
            ->with(['results' => fn ($q) => $q->where('student_id', $studentId)])
            ->get();

        $pointSum = 0;
        $count = 0;
        foreach ($assessments->groupBy('subject_id') as $group) {
            $weighted = 0;
            $weightSum = 0;
            foreach ($group as $assessment) {
                $result = $assessment->results->first();
                if (! $result || $result->score === null) {
                    continue;
                }
                $percent = ((float) $result->score / (float) $assessment->max_score) * 100;
                $w = (float) $assessment->weight ?: 1;
                $weighted += $percent * $w;
                $weightSum += $w;
            }
            if ($weightSum <= 0) {
                continue;
            }
            $graded = NectaGrading::gradeFromPercent($weighted / $weightSum);
            $pointSum += $graded['points'];
            $count++;
        }

        return $count > 0 ? $pointSum / $count : 5.0;
    }

    protected function defaultHeadRemark(string $division): string
    {
        return match ($division) {
            'I' => 'Excellent performance. Keep it up.',
            'II' => 'Very good results. Aim higher next term.',
            'III' => 'Average performance. More effort is needed.',
            'IV' => 'Below average. Requires close academic support.',
            default => 'Unsatisfactory. Immediate remedial action is required.',
        };
    }
}
