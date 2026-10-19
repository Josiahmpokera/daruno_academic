<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\CurriculumTopic;
use App\Models\GradingBand;
use App\Models\GradingSystem;
use App\Models\Subject;
use App\Support\NectaGrading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * TAMISEMI / NECTA grading systems and school curriculum frameworks.
 */
class AcademicPolicyController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Grading systems ───────────────────────────────────────────

    public function gradingIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureDefaultGradingSystems($schoolId);

            $items = GradingSystem::query()
                ->where('school_id', $schoolId)
                ->with(['bands', 'educationLevel:id,name,code'])
                ->withCount('bands')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (GradingSystem $g) => $this->transformGrading($g));

            return $this->success([
                'grading_systems' => $items,
                'stages' => $this->stageOptions(),
                'scale_types' => [
                    ['value' => 'necta_secondary', 'label' => 'NECTA secondary (CSEE/ACSEE style)'],
                    ['value' => 'primary', 'label' => 'Primary / basic education'],
                    ['value' => 'custom', 'label' => 'Custom school scale'],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function gradingStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'education_stage' => ['nullable', 'string', 'max:30'],
                'education_level_id' => ['nullable', 'integer'],
                'scale_type' => ['nullable', 'string', 'max:40'],
                'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'is_default' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string'],
                'bands' => ['nullable', 'array', 'min:1'],
                'bands.*.grade' => ['required_with:bands', 'string', 'max:10'],
                'bands.*.min_percent' => ['required_with:bands', 'numeric', 'min:0', 'max:100'],
                'bands.*.max_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'bands.*.points' => ['nullable', 'integer', 'min:0', 'max:20'],
                'bands.*.remark' => ['nullable', 'string', 'max:120'],
            ]);

            $item = DB::transaction(function () use ($schoolId, $data) {
                if (! empty($data['is_default'])) {
                    GradingSystem::query()->where('school_id', $schoolId)->update(['is_default' => false]);
                }

                $system = GradingSystem::query()->create([
                    'school_id' => $schoolId,
                    'name' => $data['name'],
                    'code' => $data['code'] ?? Str::upper(Str::slug(Str::limit($data['name'], 12, ''), '_')),
                    'education_stage' => $data['education_stage'] ?? 'all',
                    'education_level_id' => $data['education_level_id'] ?? null,
                    'scale_type' => $data['scale_type'] ?? 'necta_secondary',
                    'pass_mark' => $data['pass_mark'] ?? 30,
                    'is_default' => $data['is_default'] ?? false,
                    'is_active' => $data['is_active'] ?? true,
                    'notes' => $data['notes'] ?? null,
                ]);

                $bands = $data['bands'] ?? $this->defaultBandsForScale($system->scale_type);
                $this->syncBands($system, $bands);

                return $system->load(['bands', 'educationLevel:id,name,code']);
            });

            return $this->success([
                'grading_system' => $this->transformGrading($item),
            ], 'Grading system created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function gradingUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $system = GradingSystem::query()->where('school_id', $schoolId)->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'education_stage' => ['nullable', 'string', 'max:30'],
                'education_level_id' => ['nullable', 'integer'],
                'scale_type' => ['nullable', 'string', 'max:40'],
                'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'is_default' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string'],
                'bands' => ['nullable', 'array', 'min:1'],
                'bands.*.grade' => ['required_with:bands', 'string', 'max:10'],
                'bands.*.min_percent' => ['required_with:bands', 'numeric', 'min:0', 'max:100'],
                'bands.*.max_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'bands.*.points' => ['nullable', 'integer', 'min:0', 'max:20'],
                'bands.*.remark' => ['nullable', 'string', 'max:120'],
            ]);

            DB::transaction(function () use ($schoolId, $system, $data) {
                if (array_key_exists('is_default', $data) && $data['is_default']) {
                    GradingSystem::query()->where('school_id', $schoolId)->where('id', '!=', $system->id)
                        ->update(['is_default' => false]);
                }
                $system->fill(collect($data)->except('bands')->all())->save();
                if (! empty($data['bands'])) {
                    $this->syncBands($system, $data['bands']);
                }
            });

            return $this->success([
                'grading_system' => $this->transformGrading($system->fresh()->load(['bands', 'educationLevel:id,name,code'])),
            ], 'Grading system updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function gradingDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $system = GradingSystem::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($system->is_default) {
                return $this->error('Cannot delete the default grading system. Set another default first.', 422);
            }
            $system->delete();

            return $this->success(['deleted' => true], 'Grading system deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function gradingSetDefault(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $system = GradingSystem::query()->where('school_id', $schoolId)->findOrFail($id);
            GradingSystem::query()->where('school_id', $schoolId)->update(['is_default' => false]);
            $system->is_default = true;
            $system->is_active = true;
            $system->save();

            return $this->success([
                'grading_system' => $this->transformGrading($system->fresh()->load(['bands', 'educationLevel:id,name,code'])),
            ], 'Default grading system set');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function gradingPreview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'grading_system_id' => ['nullable', 'integer'],
            ]);

            $system = null;
            if (! empty($data['grading_system_id'])) {
                $system = GradingSystem::query()
                    ->where('school_id', $schoolId)
                    ->with('bands')
                    ->find($data['grading_system_id']);
            }
            if (! $system) {
                $system = GradingSystem::query()
                    ->where('school_id', $schoolId)
                    ->where('is_default', true)
                    ->with('bands')
                    ->first();
            }

            $percent = (float) $data['percent'];
            if ($system && $system->bands->isNotEmpty()) {
                $result = $system->gradeFromPercent($percent);
                $result['pass'] = $percent >= (float) $system->pass_mark;
                $result['grading_system_id'] = $system->id;
                $result['grading_system'] = $system->name;
            } else {
                $result = NectaGrading::gradeFromPercent($percent);
                $result['pass'] = $percent >= 30;
                $result['grading_system_id'] = null;
                $result['grading_system'] = 'Built-in NECTA';
            }
            $result['percent'] = $percent;

            return $this->success(['result' => $result]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Curriculum ────────────────────────────────────────────────

    public function curriculumIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureDefaultCurriculum($schoolId);

            $items = Curriculum::query()
                ->where('school_id', $schoolId)
                ->with([
                    'educationLevel:id,name,code',
                    'curriculumSubjects.subject:id,name,code,necta_code,education_stage,is_core',
                ])
                ->withCount(['curriculumSubjects', 'topics'])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (Curriculum $c) => $this->transformCurriculum($c));

            return $this->success([
                'curricula' => $items,
                'stages' => $this->stageOptions(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function curriculumShow(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $item = Curriculum::query()
                ->where('school_id', $schoolId)
                ->with([
                    'educationLevel:id,name,code',
                    'curriculumSubjects.subject:id,name,code,necta_code,education_stage,is_core,is_compulsory',
                    'topics.subject:id,name,code',
                ])
                ->withCount(['curriculumSubjects', 'topics'])
                ->findOrFail($id);

            return $this->success(['curriculum' => $this->transformCurriculum($item, true)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function curriculumStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'education_stage' => ['nullable', 'string', 'max:30'],
                'education_level_id' => ['nullable', 'integer'],
                'version' => ['nullable', 'string', 'max:40'],
                'authority' => ['nullable', 'string', 'max:80'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'is_default' => ['nullable', 'boolean'],
                'subject_ids' => ['nullable', 'array'],
                'subject_ids.*' => ['integer'],
            ]);

            $item = DB::transaction(function () use ($schoolId, $data) {
                if (! empty($data['is_default'])) {
                    Curriculum::query()->where('school_id', $schoolId)->update(['is_default' => false]);
                }

                $curriculum = Curriculum::query()->create([
                    'school_id' => $schoolId,
                    'name' => $data['name'],
                    'code' => $data['code'] ?? null,
                    'education_stage' => $data['education_stage'] ?? 'all',
                    'education_level_id' => $data['education_level_id'] ?? null,
                    'version' => $data['version'] ?? null,
                    'authority' => $data['authority'] ?? 'TAMISEMI',
                    'description' => $data['description'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'is_default' => $data['is_default'] ?? false,
                ]);

                if (! empty($data['subject_ids'])) {
                    $this->syncCurriculumSubjects($schoolId, $curriculum, $data['subject_ids']);
                }

                return $curriculum->load([
                    'educationLevel:id,name,code',
                    'curriculumSubjects.subject:id,name,code,necta_code,education_stage,is_core',
                ])->loadCount(['curriculumSubjects', 'topics']);
            });

            return $this->success([
                'curriculum' => $this->transformCurriculum($item),
            ], 'Curriculum created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function curriculumUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $curriculum = Curriculum::query()->where('school_id', $schoolId)->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:160'],
                'code' => ['nullable', 'string', 'max:40'],
                'education_stage' => ['nullable', 'string', 'max:30'],
                'education_level_id' => ['nullable', 'integer'],
                'version' => ['nullable', 'string', 'max:40'],
                'authority' => ['nullable', 'string', 'max:80'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'is_default' => ['nullable', 'boolean'],
                'subject_ids' => ['nullable', 'array'],
                'subject_ids.*' => ['integer'],
            ]);

            DB::transaction(function () use ($schoolId, $curriculum, $data) {
                if (array_key_exists('is_default', $data) && $data['is_default']) {
                    Curriculum::query()->where('school_id', $schoolId)->where('id', '!=', $curriculum->id)
                        ->update(['is_default' => false]);
                }
                $curriculum->fill(collect($data)->except('subject_ids')->all())->save();
                if (array_key_exists('subject_ids', $data)) {
                    $this->syncCurriculumSubjects($schoolId, $curriculum, $data['subject_ids'] ?? []);
                }
            });

            return $this->success([
                'curriculum' => $this->transformCurriculum(
                    $curriculum->fresh()->load([
                        'educationLevel:id,name,code',
                        'curriculumSubjects.subject:id,name,code,necta_code,education_stage,is_core',
                    ])->loadCount(['curriculumSubjects', 'topics'])
                ),
            ], 'Curriculum updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function curriculumDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $curriculum = Curriculum::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($curriculum->is_default) {
                return $this->error('Cannot delete the default curriculum. Set another default first.', 422);
            }
            $curriculum->delete();

            return $this->success(['deleted' => true], 'Curriculum deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function curriculumTopicStore(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $curriculum = Curriculum::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'title' => ['required', 'string', 'max:200'],
                'subject_id' => ['nullable', 'integer'],
                'code' => ['nullable', 'string', 'max:40'],
                'term_number' => ['nullable', 'integer', 'min:1', 'max:4'],
                'week_from' => ['nullable', 'integer', 'min:1', 'max:52'],
                'week_to' => ['nullable', 'integer', 'min:1', 'max:52'],
                'description' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            if (! empty($data['subject_id'])) {
                Subject::query()->where('school_id', $schoolId)->findOrFail($data['subject_id']);
            }

            $topic = CurriculumTopic::query()->create([
                'curriculum_id' => $curriculum->id,
                'subject_id' => $data['subject_id'] ?? null,
                'title' => $data['title'],
                'code' => $data['code'] ?? null,
                'term_number' => $data['term_number'] ?? null,
                'week_from' => $data['week_from'] ?? null,
                'week_to' => $data['week_to'] ?? null,
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success([
                'topic' => $this->transformTopic($topic->load('subject:id,name,code')),
            ], 'Topic added', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function curriculumTopicUpdate(Request $request, int $topicId)
    {
        try {
            $schoolId = $this->schoolId($request);
            $topic = CurriculumTopic::query()
                ->whereHas('curriculum', fn ($q) => $q->where('school_id', $schoolId))
                ->findOrFail($topicId);

            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:200'],
                'subject_id' => ['nullable', 'integer'],
                'code' => ['nullable', 'string', 'max:40'],
                'term_number' => ['nullable', 'integer', 'min:1', 'max:4'],
                'week_from' => ['nullable', 'integer', 'min:1', 'max:52'],
                'week_to' => ['nullable', 'integer', 'min:1', 'max:52'],
                'description' => ['nullable', 'string'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $topic->fill($data)->save();

            return $this->success([
                'topic' => $this->transformTopic($topic->fresh()->load('subject:id,name,code')),
            ], 'Topic updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function curriculumTopicDestroy(Request $request, int $topicId)
    {
        try {
            $schoolId = $this->schoolId($request);
            $topic = CurriculumTopic::query()
                ->whereHas('curriculum', fn ($q) => $q->where('school_id', $schoolId))
                ->findOrFail($topicId);
            $topic->delete();

            return $this->success(['deleted' => true], 'Topic deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    protected function ensureDefaultGradingSystems(int $schoolId): void
    {
        if (GradingSystem::query()->where('school_id', $schoolId)->exists()) {
            return;
        }

        $necta = GradingSystem::query()->create([
            'school_id' => $schoolId,
            'name' => 'NECTA secondary (default)',
            'code' => 'NECTA_SEC',
            'education_stage' => 'o_level',
            'scale_type' => 'necta_secondary',
            'pass_mark' => 30,
            'is_default' => true,
            'is_active' => true,
            'notes' => 'TAMISEMI / NECTA-oriented CSEE & ACSEE style letter grades (A–F). Lower grade points are better.',
        ]);
        $this->syncBands($necta, $this->defaultBandsForScale('necta_secondary'));

        $primary = GradingSystem::query()->create([
            'school_id' => $schoolId,
            'name' => 'Primary / basic education',
            'code' => 'PRIMARY',
            'education_stage' => 'primary',
            'scale_type' => 'primary',
            'pass_mark' => 40,
            'is_default' => false,
            'is_active' => true,
            'notes' => 'Common primary banding used in many Tanzanian schools.',
        ]);
        $this->syncBands($primary, $this->defaultBandsForScale('primary'));
    }

    protected function ensureDefaultCurriculum(int $schoolId): void
    {
        if (Curriculum::query()->where('school_id', $schoolId)->exists()) {
            return;
        }

        $stages = Subject::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->whereNotNull('education_stage')
            ->distinct()
            ->pluck('education_stage')
            ->filter()
            ->values();

        if ($stages->isEmpty()) {
            Curriculum::query()->create([
                'school_id' => $schoolId,
                'name' => 'School curriculum (general)',
                'code' => 'GEN',
                'education_stage' => 'all',
                'version' => (string) now()->year,
                'authority' => 'TAMISEMI',
                'description' => 'Default curriculum framework. Attach subjects from your catalogue.',
                'is_active' => true,
                'is_default' => true,
            ]);

            return;
        }

        $first = true;
        foreach ($stages as $stage) {
            $curriculum = Curriculum::query()->create([
                'school_id' => $schoolId,
                'name' => $this->stageLabel($stage).' curriculum',
                'code' => Str::upper(Str::limit(Str::slug($stage, '_'), 12, '')),
                'education_stage' => $stage,
                'version' => (string) now()->year,
                'authority' => 'TAMISEMI',
                'description' => 'Auto-seeded from active '.$this->stageLabel($stage).' subjects.',
                'is_active' => true,
                'is_default' => $first,
            ]);
            $first = false;

            $subjectIds = Subject::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->where('education_stage', $stage)
                ->orderBy('sort_order')
                ->pluck('id')
                ->all();
            $this->syncCurriculumSubjects($schoolId, $curriculum, $subjectIds);
        }
    }

    protected function defaultBandsForScale(string $scaleType): array
    {
        if ($scaleType === 'primary') {
            return [
                ['grade' => 'A', 'min_percent' => 80, 'points' => 1, 'remark' => 'Excellent'],
                ['grade' => 'B', 'min_percent' => 65, 'points' => 2, 'remark' => 'Very Good'],
                ['grade' => 'C', 'min_percent' => 50, 'points' => 3, 'remark' => 'Good'],
                ['grade' => 'D', 'min_percent' => 40, 'points' => 4, 'remark' => 'Average'],
                ['grade' => 'E', 'min_percent' => 0, 'points' => 5, 'remark' => 'Needs improvement'],
            ];
        }

        // NECTA secondary default (matches NectaGrading)
        return [
            ['grade' => 'A', 'min_percent' => 75, 'points' => 1, 'remark' => 'Excellent'],
            ['grade' => 'B', 'min_percent' => 65, 'points' => 2, 'remark' => 'Very Good'],
            ['grade' => 'C', 'min_percent' => 45, 'points' => 3, 'remark' => 'Good'],
            ['grade' => 'D', 'min_percent' => 30, 'points' => 4, 'remark' => 'Satisfactory'],
            ['grade' => 'F', 'min_percent' => 0, 'points' => 5, 'remark' => 'Fail'],
        ];
    }

    protected function syncBands(GradingSystem $system, array $bands): void
    {
        GradingBand::query()->where('grading_system_id', $system->id)->delete();
        $order = 0;
        // Sort by min_percent desc so A comes first typically
        usort($bands, fn ($a, $b) => ((float) ($b['min_percent'] ?? 0)) <=> ((float) ($a['min_percent'] ?? 0)));
        foreach ($bands as $b) {
            GradingBand::query()->create([
                'grading_system_id' => $system->id,
                'grade' => $b['grade'],
                'min_percent' => $b['min_percent'] ?? 0,
                'max_percent' => $b['max_percent'] ?? null,
                'points' => $b['points'] ?? 5,
                'remark' => $b['remark'] ?? null,
                'sort_order' => $order++,
            ]);
        }
    }

    protected function syncCurriculumSubjects(int $schoolId, Curriculum $curriculum, array $subjectIds): void
    {
        $validIds = Subject::query()
            ->where('school_id', $schoolId)
            ->whereIn('id', $subjectIds)
            ->pluck('id')
            ->all();

        CurriculumSubject::query()->where('curriculum_id', $curriculum->id)->delete();
        $order = 0;
        foreach ($validIds as $sid) {
            $subject = Subject::query()->find($sid);
            CurriculumSubject::query()->create([
                'curriculum_id' => $curriculum->id,
                'subject_id' => $sid,
                'is_compulsory' => (bool) ($subject?->is_compulsory ?? $subject?->is_core ?? true),
                'weekly_periods' => $subject?->weekly_periods,
                'sort_order' => $order++,
            ]);
        }
    }

    protected function transformGrading(GradingSystem $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'code' => $g->code,
            'education_stage' => $g->education_stage,
            'education_level_id' => $g->education_level_id,
            'education_level' => $g->educationLevel ? [
                'id' => $g->educationLevel->id,
                'name' => $g->educationLevel->name,
            ] : null,
            'scale_type' => $g->scale_type,
            'pass_mark' => (float) $g->pass_mark,
            'is_default' => (bool) $g->is_default,
            'is_active' => (bool) $g->is_active,
            'notes' => $g->notes,
            'bands_count' => (int) ($g->bands_count ?? $g->bands?->count() ?? 0),
            'bands' => ($g->relationLoaded('bands') ? $g->bands : collect())->map(fn (GradingBand $b) => [
                'id' => $b->id,
                'grade' => $b->grade,
                'min_percent' => (float) $b->min_percent,
                'max_percent' => $b->max_percent !== null ? (float) $b->max_percent : null,
                'points' => (int) $b->points,
                'remark' => $b->remark,
                'sort_order' => (int) $b->sort_order,
            ])->values(),
            'created_at' => $g->created_at,
        ];
    }

    protected function transformCurriculum(Curriculum $c, bool $detailed = false): array
    {
        $subjects = ($c->relationLoaded('curriculumSubjects') ? $c->curriculumSubjects : collect())
            ->map(function (CurriculumSubject $cs) {
                return [
                    'id' => $cs->id,
                    'subject_id' => $cs->subject_id,
                    'is_compulsory' => (bool) $cs->is_compulsory,
                    'weekly_periods' => $cs->weekly_periods,
                    'sort_order' => (int) $cs->sort_order,
                    'subject' => $cs->subject ? [
                        'id' => $cs->subject->id,
                        'name' => $cs->subject->name,
                        'code' => $cs->subject->code,
                        'necta_code' => $cs->subject->necta_code,
                        'education_stage' => $cs->subject->education_stage,
                        'is_core' => (bool) $cs->subject->is_core,
                    ] : null,
                ];
            })->values();

        $base = [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'education_stage' => $c->education_stage,
            'education_level_id' => $c->education_level_id,
            'education_level' => $c->educationLevel ? [
                'id' => $c->educationLevel->id,
                'name' => $c->educationLevel->name,
            ] : null,
            'version' => $c->version,
            'authority' => $c->authority,
            'description' => $c->description,
            'is_active' => (bool) $c->is_active,
            'is_default' => (bool) $c->is_default,
            'subjects_count' => (int) ($c->curriculum_subjects_count ?? $subjects->count()),
            'topics_count' => (int) ($c->topics_count ?? 0),
            'subjects' => $subjects,
            'created_at' => $c->created_at,
        ];

        if ($detailed) {
            $base['topics'] = ($c->relationLoaded('topics') ? $c->topics : collect())
                ->map(fn (CurriculumTopic $t) => $this->transformTopic($t))
                ->values();
        }

        return $base;
    }

    protected function transformTopic(CurriculumTopic $t): array
    {
        return [
            'id' => $t->id,
            'curriculum_id' => $t->curriculum_id,
            'subject_id' => $t->subject_id,
            'subject' => $t->relationLoaded('subject') && $t->subject ? [
                'id' => $t->subject->id,
                'name' => $t->subject->name,
                'code' => $t->subject->code,
            ] : null,
            'title' => $t->title,
            'code' => $t->code,
            'term_number' => $t->term_number,
            'week_from' => $t->week_from,
            'week_to' => $t->week_to,
            'description' => $t->description,
            'sort_order' => (int) $t->sort_order,
            'is_active' => (bool) $t->is_active,
        ];
    }

    protected function stageOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'All stages'],
            ['value' => 'pre_primary', 'label' => 'Pre-primary'],
            ['value' => 'primary', 'label' => 'Primary'],
            ['value' => 'o_level', 'label' => 'O-level (secondary)'],
            ['value' => 'a_level', 'label' => 'A-level (advanced)'],
        ];
    }

    protected function stageLabel(string $stage): string
    {
        return match ($stage) {
            'pre_primary' => 'Pre-primary',
            'primary' => 'Primary',
            'o_level' => 'O-level',
            'a_level' => 'A-level',
            default => ucfirst(str_replace('_', ' ', $stage)),
        };
    }
}
