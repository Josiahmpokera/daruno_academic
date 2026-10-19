<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\EducationLevel;
use App\Models\Stream;
use App\Models\Subject;
use App\Models\Term;
use App\Support\EducationStages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AcademicController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ─── Academic Years ───────────────────────────────────────────

    public function yearsIndex(Request $request)
    {
        try {
            $years = AcademicYear::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount('terms')
                ->orderByDesc('is_current')
                ->orderByDesc('starts_at')
                ->get();

            return $this->success(['academic_years' => $years]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function yearsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
                'is_current' => ['nullable', 'boolean'],
            ]);

            $year = DB::transaction(function () use ($schoolId, $data) {
                if (! empty($data['is_current'])) {
                    AcademicYear::query()->where('school_id', $schoolId)->update(['is_current' => false]);
                }

                return AcademicYear::query()->create([
                    'school_id' => $schoolId,
                    'name' => $data['name'],
                    'starts_at' => $data['starts_at'] ?? null,
                    'ends_at' => $data['ends_at'] ?? null,
                    'is_current' => (bool) ($data['is_current'] ?? false),
                ]);
            });

            return $this->success(['academic_year' => $year->loadCount('terms')], 'Academic year created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function yearsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $year = AcademicYear::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:100'],
                'starts_at' => ['sometimes', 'nullable', 'date'],
                'ends_at' => ['sometimes', 'nullable', 'date'],
                'is_current' => ['sometimes', 'boolean'],
            ]);

            DB::transaction(function () use ($schoolId, $year, $data) {
                if (array_key_exists('is_current', $data) && $data['is_current']) {
                    AcademicYear::query()->where('school_id', $schoolId)->update(['is_current' => false]);
                }
                $year->fill($data);
                $year->save();
            });

            return $this->success(['academic_year' => $year->fresh()->loadCount('terms')], 'Academic year updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function yearsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $year = AcademicYear::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($year->terms()->exists()) {
                return $this->error('Cannot delete year that has terms. Remove terms first.', 422);
            }
            $year->delete();

            return $this->success(['deleted' => true], 'Academic year deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Terms ────────────────────────────────────────────────────

    public function termsIndex(Request $request)
    {
        try {
            $query = Term::query()
                ->where('school_id', $this->schoolId($request))
                ->with('academicYear:id,name,is_current');

            if ($request->integer('academic_year_id')) {
                $query->where('academic_year_id', $request->integer('academic_year_id'));
            }

            $terms = $query->orderBy('starts_at')->orderBy('name')->get();

            return $this->success(['terms' => $terms]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function termsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'academic_year_id' => ['required', 'integer'],
                'name' => ['required', 'string', 'max:100'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date'],
            ]);

            AcademicYear::query()->where('school_id', $schoolId)->findOrFail($data['academic_year_id']);

            $term = Term::query()->create([
                'school_id' => $schoolId,
                ...$data,
            ]);

            return $this->success([
                'term' => $term->load('academicYear:id,name,is_current'),
            ], 'Term created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function termsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $term = Term::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'academic_year_id' => ['sometimes', 'integer'],
                'name' => ['sometimes', 'string', 'max:100'],
                'starts_at' => ['sometimes', 'nullable', 'date'],
                'ends_at' => ['sometimes', 'nullable', 'date'],
            ]);

            if (isset($data['academic_year_id'])) {
                AcademicYear::query()->where('school_id', $schoolId)->findOrFail($data['academic_year_id']);
            }

            $term->fill($data);
            $term->save();

            return $this->success([
                'term' => $term->fresh()->load('academicYear:id,name,is_current'),
            ], 'Term updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function termsDestroy(Request $request, int $id)
    {
        try {
            $term = Term::query()->where('school_id', $this->schoolId($request))->findOrFail($id);
            $term->delete();

            return $this->success(['deleted' => true], 'Term deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Education levels ─────────────────────────────────────────

    public function levelsIndex(Request $request)
    {
        try {
            $school = $this->school($request);
            $offered = $school->offeredStages();

            $levels = EducationLevel::query()
                ->where('school_id', $school->id)
                ->where('is_active', true)
                ->withCount('classrooms')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            // Only levels that map to stages this school operates
            if ($offered !== []) {
                $levels = $levels
                    ->filter(function (EducationLevel $level) use ($offered) {
                        $stage = EducationStages::fromLevelCodeOrName($level->code, $level->name);

                        return $stage && in_array($stage, $offered, true);
                    })
                    ->values();
            }

            return $this->success([
                'levels' => $levels,
                'school_education_stages' => $offered,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function levelsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'code' => ['nullable', 'string', 'max:30'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'description' => ['nullable', 'string', 'max:1000'],
            ]);

            $level = EducationLevel::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'description' => $data['description'] ?? null,
            ]);

            return $this->success(['level' => $level->loadCount('classrooms')], 'Level created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function levelsUpdate(Request $request, int $id)
    {
        try {
            $level = EducationLevel::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:100'],
                'code' => ['sometimes', 'nullable', 'string', 'max:30'],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
                'is_active' => ['sometimes', 'boolean'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]);

            $level->fill($data);
            $level->save();

            return $this->success(['level' => $level->loadCount('classrooms')], 'Level updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function levelsDestroy(Request $request, int $id)
    {
        try {
            $level = EducationLevel::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($level->classrooms()->exists()) {
                $level->is_active = false;
                $level->save();

                return $this->success([
                    'level' => $level->loadCount('classrooms'),
                    'deactivated' => true,
                ], 'Level has classes and was deactivated instead of deleted');
            }

            $level->delete();

            return $this->success(['deleted' => true], 'Level deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Streams ──────────────────────────────────────────────────

    public function streamsIndex(Request $request)
    {
        try {
            $streams = Stream::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount('classrooms')
                ->orderBy('name')
                ->get();

            return $this->success(['streams' => $streams]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function streamsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'code' => ['nullable', 'string', 'max:30'],
                'is_active' => ['nullable', 'boolean'],
                'description' => ['nullable', 'string', 'max:1000'],
            ]);

            $stream = Stream::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'description' => $data['description'] ?? null,
            ]);

            return $this->success(['stream' => $stream->loadCount('classrooms')], 'Stream created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function streamsUpdate(Request $request, int $id)
    {
        try {
            $stream = Stream::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:100'],
                'code' => ['sometimes', 'nullable', 'string', 'max:30'],
                'is_active' => ['sometimes', 'boolean'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]);

            $stream->fill($data);
            $stream->save();

            return $this->success(['stream' => $stream->loadCount('classrooms')], 'Stream updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function streamsDestroy(Request $request, int $id)
    {
        try {
            $stream = Stream::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($stream->classrooms()->exists()) {
                $stream->is_active = false;
                $stream->save();

                return $this->success([
                    'stream' => $stream->loadCount('classrooms'),
                    'deactivated' => true,
                ], 'Stream has classes and was deactivated instead of deleted');
            }

            $stream->delete();

            return $this->success(['deleted' => true], 'Stream deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Classes (classrooms) ─────────────────────────────────────

    public function classesIndex(Request $request)
    {
        try {
            $school = $this->school($request);
            $offered = $school->offeredStages();

            $classes = Classroom::query()
                ->where('school_id', $school->id)
                ->where('is_active', true)
                ->with(['educationLevel:id,name,code,sort_order', 'stream:id,name,code'])
                ->withCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name')
                ->get();

            // Filter to classes whose education level matches school stages
            if ($offered !== []) {
                $classes = $classes
                    ->filter(function (Classroom $class) use ($offered) {
                        $level = $class->educationLevel;
                        if (! $level) {
                            return false;
                        }
                        $stage = EducationStages::fromLevelCodeOrName($level->code, $level->name);

                        return $stage && in_array($stage, $offered, true);
                    })
                    ->values();
            }

            // Stable order: level sort then class name
            $classes = $classes->sortBy(function (Classroom $class) {
                $order = (int) ($class->educationLevel?->sort_order ?? 999);
                $name = strtolower((string) $class->name);

                return sprintf('%04d-%s', $order, $name);
            })->values();

            return $this->success([
                'classes' => $classes,
                'school_education_stages' => $offered,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function classesStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'code' => ['nullable', 'string', 'max:50'],
                'education_level_id' => ['nullable', 'integer', $this->tenantExists($request, 'education_levels')],
                'stream_id' => ['nullable', 'integer', $this->tenantExists($request, 'streams')],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $this->assertLevelStream($schoolId, $data['education_level_id'] ?? null, $data['stream_id'] ?? null);

            $class = Classroom::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'education_level_id' => $data['education_level_id'] ?? null,
                'stream_id' => $data['stream_id'] ?? null,
                'capacity' => $data['capacity'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success([
                'class' => $class->load(['educationLevel:id,name,code', 'stream:id,name,code'])
                    ->loadCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')]),
            ], 'Class created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function classesUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $class = Classroom::query()->where('school_id', $schoolId)->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:100'],
                'code' => ['sometimes', 'nullable', 'string', 'max:50'],
                'education_level_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists($request, 'education_levels')],
                'stream_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists($request, 'streams')],
                'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $this->assertLevelStream(
                $schoolId,
                $data['education_level_id'] ?? $class->education_level_id,
                $data['stream_id'] ?? $class->stream_id,
            );

            $class->fill($data);
            $class->save();

            return $this->success([
                'class' => $class->fresh(['educationLevel:id,name,code', 'stream:id,name,code'])
                    ->loadCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')]),
            ], 'Class updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function classesDestroy(Request $request, int $id)
    {
        try {
            $class = Classroom::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            if ($class->enrollments()->where('status', 'active')->exists()) {
                $class->is_active = false;
                $class->save();

                return $this->success([
                    'class' => $class,
                    'deactivated' => true,
                ], 'Class has active enrollments and was deactivated');
            }

            $class->delete();

            return $this->success(['id' => $id], 'Class deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    // ─── Subjects ─────────────────────────────────────────────────

    public function subjectsIndex(Request $request)
    {
        try {
            $subjects = Subject::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('name')
                ->get();

            return $this->success(['subjects' => $subjects]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function subjectsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:30'],
                'is_core' => ['nullable', 'boolean'],
            ]);

            $subject = Subject::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_core' => $data['is_core'] ?? true,
            ]);

            return $this->success(['subject' => $subject], 'Subject created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function subjectsUpdate(Request $request, int $id)
    {
        try {
            $subject = Subject::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['sometimes', 'nullable', 'string', 'max:30'],
                'is_core' => ['sometimes', 'boolean'],
            ]);

            $subject->fill($data);
            $subject->save();

            return $this->success(['subject' => $subject], 'Subject updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function subjectsDestroy(Request $request, int $id)
    {
        try {
            $subject = Subject::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $subject->delete();

            return $this->success(['id' => $id], 'Subject deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    protected function assertLevelStream(int $schoolId, ?int $levelId, ?int $streamId): void
    {
        if ($levelId) {
            $ok = EducationLevel::query()->where('school_id', $schoolId)->where('id', $levelId)->exists();
            if (! $ok) {
                throw new \InvalidArgumentException('Education level does not belong to this school.');
            }
        }
        if ($streamId) {
            $ok = Stream::query()->where('school_id', $schoolId)->where('id', $streamId)->exists();
            if (! $ok) {
                throw new \InvalidArgumentException('Stream does not belong to this school.');
            }
        }
    }
}
