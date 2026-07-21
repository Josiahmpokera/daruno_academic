<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\LearningResource;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TAMISEMI-aligned learning resources, smart classroom and assignments.
 */
class LearningController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public const RESOURCE_TYPES = ['note', 'past_paper', 'video', 'digital_book'];

    // ── Overview ──────────────────────────────────────────────────

    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $byType = LearningResource::query()
                ->where('school_id', $schoolId)
                ->where('status', '!=', 'archived')
                ->selectRaw('type, count(*) as total')
                ->groupBy('type')
                ->pluck('total', 'type');

            $courses = Course::query()->where('school_id', $schoolId)->count();
            $lessons = Lesson::query()
                ->whereHas('course', fn ($q) => $q->where('school_id', $schoolId))
                ->count();
            $assignments = Assignment::query()->where('school_id', $schoolId)->count();
            $openAssignments = Assignment::query()
                ->where('school_id', $schoolId)
                ->where('status', 'published')
                ->where(function ($q) {
                    $q->whereNull('due_at')->orWhere('due_at', '>=', now());
                })
                ->count();
            $submissions = AssignmentSubmission::query()
                ->whereHas('assignment', fn ($q) => $q->where('school_id', $schoolId))
                ->count();
            $pendingGrading = AssignmentSubmission::query()
                ->whereHas('assignment', fn ($q) => $q->where('school_id', $schoolId))
                ->whereIn('status', ['submitted', 'late'])
                ->whereNull('score')
                ->count();

            return $this->success([
                'summary' => [
                    'notes' => (int) ($byType['note'] ?? 0),
                    'past_papers' => (int) ($byType['past_paper'] ?? 0),
                    'videos' => (int) ($byType['video'] ?? 0),
                    'digital_books' => (int) ($byType['digital_book'] ?? 0),
                    'resources_total' => array_sum(array_map('intval', $byType->all())),
                    'courses' => $courses,
                    'lessons' => $lessons,
                    'assignments' => $assignments,
                    'open_assignments' => $openAssignments,
                    'submissions' => $submissions,
                    'pending_grading' => $pendingGrading,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Learning resources (notes / past papers / videos / books) ─

    public function resourcesIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $type = $request->string('type')->toString();
            $query = LearningResource::query()
                ->where('school_id', $schoolId)
                ->with([
                    'subject:id,name,code',
                    'classroom:id,name',
                    'educationLevel:id,name',
                    'academicYear:id,name',
                    'term:id,name',
                ])
                ->orderByDesc('id');

            if ($type !== '' && in_array($type, self::RESOURCE_TYPES, true)) {
                $query->where('type', $type);
            }
            if ($request->integer('subject_id')) {
                $query->where('subject_id', $request->integer('subject_id'));
            }
            if ($request->integer('classroom_id')) {
                $query->where('classroom_id', $request->integer('classroom_id'));
            }
            if ($request->integer('education_level_id')) {
                $query->where('education_level_id', $request->integer('education_level_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            } else {
                $query->where('status', '!=', 'archived');
            }
            $q = $request->string('q')->toString();
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('title', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhere('paper_code', 'like', "%{$q}%")
                        ->orWhere('class_level', 'like', "%{$q}%");
                });
            }
            if ($request->integer('exam_year')) {
                $query->where('exam_year', $request->integer('exam_year'));
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'resources' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function resourcesStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'type' => ['required', 'in:note,past_paper,video,digital_book'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'subject_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'education_level_id' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'file_path' => ['nullable', 'string', 'max:500'],
                'external_url' => ['nullable', 'string', 'max:500'],
                'mime_type' => ['nullable', 'string', 'max:80'],
                'exam_body' => ['nullable', 'string', 'max:40'],
                'exam_year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
                'paper_code' => ['nullable', 'string', 'max:40'],
                'class_level' => ['nullable', 'string', 'max:40'],
                'language' => ['nullable', 'string', 'max:20'],
                'status' => ['nullable', 'in:draft,published,archived'],
                'is_featured' => ['nullable', 'boolean'],
            ]);

            if (empty($data['file_path']) && empty($data['external_url'])) {
                // Allow draft notes without file; published content should have a source
                if (($data['status'] ?? 'published') === 'published' && $data['type'] !== 'note') {
                    return $this->error('Provide a file path or external URL for this resource.', 422);
                }
            }

            $item = LearningResource::query()->create([
                'school_id' => $schoolId,
                ...$data,
                'status' => $data['status'] ?? 'published',
                'is_featured' => $data['is_featured'] ?? false,
                'created_by' => $request->user()?->id,
            ]);

            return $this->success([
                'resource' => $item->load(['subject', 'classroom', 'educationLevel']),
            ], 'Resource created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function resourcesUpdate(Request $request, int $id)
    {
        try {
            $item = LearningResource::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'subject_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'education_level_id' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'file_path' => ['nullable', 'string', 'max:500'],
                'external_url' => ['nullable', 'string', 'max:500'],
                'mime_type' => ['nullable', 'string', 'max:80'],
                'exam_body' => ['nullable', 'string', 'max:40'],
                'exam_year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
                'paper_code' => ['nullable', 'string', 'max:40'],
                'class_level' => ['nullable', 'string', 'max:40'],
                'language' => ['nullable', 'string', 'max:20'],
                'status' => ['nullable', 'in:draft,published,archived'],
                'is_featured' => ['nullable', 'boolean'],
            ]);
            $item->fill($data)->save();

            return $this->success([
                'resource' => $item->fresh()->load(['subject', 'classroom', 'educationLevel']),
            ], 'Resource updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function resourcesDestroy(Request $request, int $id)
    {
        try {
            $item = LearningResource::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $item->status = 'archived';
            $item->save();

            return $this->success(['resource' => $item], 'Resource archived');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function resourcesTrack(Request $request, int $id)
    {
        try {
            $item = LearningResource::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $action = $request->string('action')->toString() ?: 'view';
            if ($action === 'download') {
                $item->download_count = (int) $item->download_count + 1;
            } else {
                $item->view_count = (int) $item->view_count + 1;
            }
            $item->save();

            return $this->success(['resource' => $item->fresh()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Smart classroom: courses + lessons ────────────────────────

    public function coursesIndex(Request $request)
    {
        try {
            $query = Course::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'subject:id,name,code',
                    'classroom:id,name',
                    'teacher:id,first_name,last_name',
                    'academicYear:id,name',
                    'term:id,name',
                ])
                ->withCount('lessons')
                ->orderByDesc('id');

            if ($request->integer('classroom_id')) {
                $query->where('classroom_id', $request->integer('classroom_id'));
            }
            if ($request->integer('subject_id')) {
                $query->where('subject_id', $request->integer('subject_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }

            return $this->success(['courses' => $query->limit(200)->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function coursesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'code' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string'],
                'classroom_id' => ['nullable', 'integer'],
                'subject_id' => ['nullable', 'integer'],
                'teacher_id' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'in:active,draft,archived'],
                'cover_url' => ['nullable', 'string', 'max:500'],
            ]);

            $item = Course::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success([
                'course' => $item->load(['subject', 'classroom', 'teacher'])->loadCount('lessons'),
            ], 'Course created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function coursesUpdate(Request $request, int $id)
    {
        try {
            $item = Course::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'code' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string'],
                'classroom_id' => ['nullable', 'integer'],
                'subject_id' => ['nullable', 'integer'],
                'teacher_id' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'in:active,draft,archived'],
                'cover_url' => ['nullable', 'string', 'max:500'],
            ]);
            $item->fill($data)->save();

            return $this->success([
                'course' => $item->fresh()->load(['subject', 'classroom', 'teacher'])->loadCount('lessons'),
            ], 'Course updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function coursesShow(Request $request, int $id)
    {
        try {
            $item = Course::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'subject', 'classroom', 'teacher', 'academicYear', 'term',
                    'lessons.attachments',
                ])
                ->findOrFail($id);

            return $this->success(['course' => $item]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function lessonsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'course_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'summary' => ['nullable', 'string', 'max:255'],
                'content' => ['nullable', 'string'],
                'video_url' => ['nullable', 'string', 'max:500'],
                'duration_minutes' => ['nullable', 'integer', 'min:0'],
                'position' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'in:draft,published,archived'],
                'attachments' => ['nullable', 'array'],
                'attachments.*.type' => ['nullable', 'string', 'max:30'],
                'attachments.*.path' => ['required_with:attachments', 'string', 'max:500'],
            ]);

            $course = Course::query()->where('school_id', $schoolId)->findOrFail($data['course_id']);

            $lesson = DB::transaction(function () use ($course, $data) {
                $pos = $data['position'] ?? ((int) Lesson::query()->where('course_id', $course->id)->max('position') + 1);
                $lesson = Lesson::query()->create([
                    'course_id' => $course->id,
                    'title' => $data['title'],
                    'summary' => $data['summary'] ?? null,
                    'content' => $data['content'] ?? null,
                    'video_url' => $data['video_url'] ?? null,
                    'duration_minutes' => $data['duration_minutes'] ?? null,
                    'position' => $pos,
                    'status' => $data['status'] ?? 'published',
                ]);
                foreach ($data['attachments'] ?? [] as $att) {
                    LessonAttachment::query()->create([
                        'lesson_id' => $lesson->id,
                        'type' => $att['type'] ?? 'file',
                        'path' => $att['path'],
                    ]);
                }

                return $lesson->load('attachments');
            });

            return $this->success(['lesson' => $lesson], 'Lesson added', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function lessonsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $lesson = Lesson::query()
                ->whereHas('course', fn ($q) => $q->where('school_id', $schoolId))
                ->findOrFail($id);
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'summary' => ['nullable', 'string', 'max:255'],
                'content' => ['nullable', 'string'],
                'video_url' => ['nullable', 'string', 'max:500'],
                'duration_minutes' => ['nullable', 'integer', 'min:0'],
                'position' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'in:draft,published,archived'],
            ]);
            $lesson->fill($data)->save();

            return $this->success(['lesson' => $lesson->fresh()->load('attachments')], 'Lesson updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function lessonsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $lesson = Lesson::query()
                ->whereHas('course', fn ($q) => $q->where('school_id', $schoolId))
                ->findOrFail($id);
            $lesson->attachments()->delete();
            $lesson->delete();

            return $this->success(['deleted' => true], 'Lesson deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Assignments ───────────────────────────────────────────────

    public function assignmentsIndex(Request $request)
    {
        try {
            $query = Assignment::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'subject:id,name,code',
                    'classroom:id,name',
                    'teacher:id,first_name,last_name',
                    'academicYear:id,name',
                    'term:id,name',
                ])
                ->withCount('submissions')
                ->orderByDesc('due_at')
                ->orderByDesc('id');

            if ($request->string('type')->toString() !== '') {
                $query->where('type', $request->string('type')->toString());
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }
            if ($request->integer('classroom_id')) {
                $query->where('classroom_id', $request->integer('classroom_id'));
            }
            if ($request->integer('subject_id')) {
                $query->where('subject_id', $request->integer('subject_id'));
            }
            if ($request->boolean('due_soon')) {
                $query->where('status', 'published')
                    ->whereNotNull('due_at')
                    ->whereBetween('due_at', [now(), now()->addDays(7)]);
            }
            if ($request->boolean('overdue')) {
                $query->where('status', 'published')
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now());
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'assignments' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function assignmentsStore(Request $request)
    {
        try {
            $data = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'instructions' => ['nullable', 'string'],
                'classroom_id' => ['nullable', 'integer'],
                'subject_id' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'teacher_id' => ['nullable', 'integer'],
                'assigned_at' => ['nullable', 'date'],
                'due_at' => ['nullable', 'date'],
                'max_score' => ['nullable', 'numeric', 'min:0'],
                'allow_late' => ['nullable', 'boolean'],
                'type' => ['nullable', 'in:homework,quiz,project,assignment'],
                'status' => ['nullable', 'in:draft,published,closed'],
                'attachment_path' => ['nullable', 'string', 'max:500'],
            ]);

            $item = Assignment::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'type' => $data['type'] ?? 'homework',
                'status' => $data['status'] ?? 'published',
                'max_score' => $data['max_score'] ?? 100,
                'allow_late' => $data['allow_late'] ?? true,
                'assigned_at' => $data['assigned_at'] ?? now(),
            ]);

            return $this->success([
                'assignment' => $item->load(['subject', 'classroom', 'teacher'])->loadCount('submissions'),
            ], 'Assignment created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function assignmentsUpdate(Request $request, int $id)
    {
        try {
            $item = Assignment::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'instructions' => ['nullable', 'string'],
                'classroom_id' => ['nullable', 'integer'],
                'subject_id' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'teacher_id' => ['nullable', 'integer'],
                'assigned_at' => ['nullable', 'date'],
                'due_at' => ['nullable', 'date'],
                'max_score' => ['nullable', 'numeric', 'min:0'],
                'allow_late' => ['nullable', 'boolean'],
                'type' => ['nullable', 'in:homework,quiz,project,assignment'],
                'status' => ['nullable', 'in:draft,published,closed'],
                'attachment_path' => ['nullable', 'string', 'max:500'],
            ]);
            $item->fill($data)->save();

            return $this->success([
                'assignment' => $item->fresh()->load(['subject', 'classroom', 'teacher'])->loadCount('submissions'),
            ], 'Assignment updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function assignmentsShow(Request $request, int $id)
    {
        try {
            $item = Assignment::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'subject', 'classroom', 'teacher', 'academicYear', 'term',
                    'submissions.student:id,first_name,last_name,admission_number',
                ])
                ->findOrFail($id);

            return $this->success(['assignment' => $item]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function submissionsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $query = AssignmentSubmission::query()
                ->whereHas('assignment', fn ($q) => $q->where('school_id', $schoolId))
                ->with([
                    'assignment:id,title,type,due_at,max_score,classroom_id,subject_id',
                    'assignment.subject:id,name',
                    'assignment.classroom:id,name',
                    'student:id,first_name,last_name,admission_number',
                ])
                ->orderByDesc('submitted_at')
                ->orderByDesc('id');

            if ($request->integer('assignment_id')) {
                $query->where('assignment_id', $request->integer('assignment_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }
            if ($request->boolean('ungraded')) {
                $query->whereNull('score')->whereIn('status', ['submitted', 'late']);
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'submissions' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function submissionsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'assignment_id' => ['required', 'integer'],
                'student_id' => ['required', 'integer'],
                'content' => ['nullable', 'string'],
                'attachment_path' => ['nullable', 'string', 'max:500'],
            ]);

            $assignment = Assignment::query()
                ->where('school_id', $schoolId)
                ->findOrFail($data['assignment_id']);

            if ($assignment->status === 'closed') {
                return $this->error('This assignment is closed for submissions.', 422);
            }

            Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);

            $now = now();
            $late = $assignment->due_at && $now->gt($assignment->due_at);
            if ($late && ! $assignment->allow_late) {
                return $this->error('Late submissions are not allowed for this assignment.', 422);
            }

            $submission = AssignmentSubmission::query()->updateOrCreate(
                [
                    'assignment_id' => $assignment->id,
                    'student_id' => $data['student_id'],
                ],
                [
                    'content' => $data['content'] ?? null,
                    'attachment_path' => $data['attachment_path'] ?? null,
                    'submitted_at' => $now,
                    'status' => $late ? 'late' : 'submitted',
                    // Keep prior grade if re-submitting under review workflow — clear score on re-submit
                    'score' => null,
                    'grade' => null,
                    'feedback' => null,
                    'graded_at' => null,
                    'graded_by' => null,
                ]
            );

            return $this->success([
                'submission' => $submission->load(['student', 'assignment']),
            ], 'Submission recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function submissionsGrade(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'score' => ['required', 'numeric', 'min:0'],
                'grade' => ['nullable', 'string', 'max:5'],
                'feedback' => ['nullable', 'string'],
            ]);

            $submission = AssignmentSubmission::query()
                ->whereHas('assignment', fn ($q) => $q->where('school_id', $schoolId))
                ->with('assignment')
                ->findOrFail($id);

            $max = (float) ($submission->assignment->max_score ?? 100);
            if ((float) $data['score'] > $max + 0.001) {
                return $this->error("Score cannot exceed max ({$max}).", 422);
            }

            $submission->score = $data['score'];
            $submission->grade = $data['grade'] ?? $this->letterGrade((float) $data['score'], $max);
            $submission->feedback = $data['feedback'] ?? null;
            $submission->status = 'graded';
            $submission->graded_at = now();
            $submission->graded_by = $request->user()?->id;
            $submission->save();

            return $this->success([
                'submission' => $submission->fresh()->load(['student', 'assignment']),
            ], 'Feedback saved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reportsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $byType = LearningResource::query()
                ->where('school_id', $schoolId)
                ->where('status', 'published')
                ->selectRaw('type, count(*) as total, sum(view_count) as views, sum(download_count) as downloads')
                ->groupBy('type')
                ->get();

            $assignmentStats = Assignment::query()
                ->where('school_id', $schoolId)
                ->selectRaw("type, count(*) as total, sum(case when status='published' then 1 else 0 end) as published")
                ->groupBy('type')
                ->get();

            $graded = AssignmentSubmission::query()
                ->whereHas('assignment', fn ($q) => $q->where('school_id', $schoolId))
                ->where('status', 'graded')
                ->count();
            $avgScore = (float) AssignmentSubmission::query()
                ->whereHas('assignment', fn ($q) => $q->where('school_id', $schoolId))
                ->whereNotNull('score')
                ->avg('score');

            return $this->success([
                'resources_by_type' => $byType,
                'assignments_by_type' => $assignmentStats,
                'submissions' => [
                    'graded' => $graded,
                    'average_score' => round($avgScore, 2),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    protected function letterGrade(float $score, float $max): string
    {
        if ($max <= 0) {
            return 'F';
        }
        $pct = ($score / $max) * 100;
        // NECTA-inspired division bands (simplified)
        return match (true) {
            $pct >= 75 => 'A',
            $pct >= 65 => 'B',
            $pct >= 45 => 'C',
            $pct >= 30 => 'D',
            default => 'F',
        };
    }
}
