<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\EducationLevel;
use App\Models\School;
use App\Models\Stream;
use App\Models\Term;
use App\Support\EducationStages;
use Illuminate\Database\Seeder;

class AcademicStructureSeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::query()->get();
        if ($schools->isEmpty()) {
            $this->command?->warn('AcademicStructureSeeder: no schools found.');

            return;
        }

        $levelSeed = [
            ['stage' => EducationStages::PRE_PRIMARY, 'name' => 'Pre-Primary', 'code' => 'PP', 'sort_order' => 1],
            ['stage' => EducationStages::PRIMARY, 'name' => 'Primary', 'code' => 'PR', 'sort_order' => 2],
            ['stage' => EducationStages::ORDINARY_LEVEL, 'name' => 'Ordinary Level', 'code' => 'O', 'sort_order' => 3],
            ['stage' => EducationStages::ADVANCED_LEVEL, 'name' => 'Advanced Level', 'code' => 'A', 'sort_order' => 4],
        ];

        $streamSeed = [
            ['name' => 'Stream A', 'code' => 'A'],
            ['name' => 'Stream B', 'code' => 'B'],
            ['name' => 'Stream C', 'code' => 'C'],
            ['name' => 'Science', 'code' => 'SCI'],
            ['name' => 'Arts', 'code' => 'ART'],
            ['name' => 'General', 'code' => 'GEN'],
        ];

        /** @var array<string, list<array{name: string, code: string, stream: string}>> */
        $classesByStage = [
            EducationStages::PRE_PRIMARY => [
                ['name' => 'Baby Class', 'code' => 'PP-BABY', 'stream' => 'GEN'],
                ['name' => 'Middle Class', 'code' => 'PP-MID', 'stream' => 'GEN'],
                ['name' => 'Pre-Unit', 'code' => 'PP-UNIT', 'stream' => 'GEN'],
            ],
            EducationStages::PRIMARY => [
                ['name' => 'Std I A', 'code' => 'STD1A', 'stream' => 'A'],
                ['name' => 'Std II A', 'code' => 'STD2A', 'stream' => 'A'],
                ['name' => 'Std III A', 'code' => 'STD3A', 'stream' => 'A'],
                ['name' => 'Std IV A', 'code' => 'STD4A', 'stream' => 'A'],
                ['name' => 'Std V A', 'code' => 'STD5A', 'stream' => 'A'],
                ['name' => 'Std VI A', 'code' => 'STD6A', 'stream' => 'A'],
                ['name' => 'Std VII A', 'code' => 'STD7A', 'stream' => 'A'],
            ],
            EducationStages::ORDINARY_LEVEL => [
                ['name' => 'Form 1 A', 'code' => 'F1A', 'stream' => 'A'],
                ['name' => 'Form 1 B', 'code' => 'F1B', 'stream' => 'B'],
                ['name' => 'Form 2 A', 'code' => 'F2A', 'stream' => 'A'],
                ['name' => 'Form 3 A', 'code' => 'F3A', 'stream' => 'A'],
                ['name' => 'Form 4 A', 'code' => 'F4A', 'stream' => 'A'],
            ],
            EducationStages::ADVANCED_LEVEL => [
                ['name' => 'Form 5 Science', 'code' => 'F5SCI', 'stream' => 'SCI'],
                ['name' => 'Form 5 Arts', 'code' => 'F5ART', 'stream' => 'ART'],
                ['name' => 'Form 6 Science', 'code' => 'F6SCI', 'stream' => 'SCI'],
                ['name' => 'Form 6 Arts', 'code' => 'F6ART', 'stream' => 'ART'],
            ],
        ];

        foreach ($schools as $school) {
            $offered = $school->offeredStages();
            // No stages configured → seed full structure (demo / legacy)
            $activeStages = $offered !== [] ? $offered : EducationStages::keys();

            $levelIds = [];
            foreach ($levelSeed as $row) {
                $level = EducationLevel::query()->updateOrCreate(
                    ['school_id' => $school->id, 'code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'sort_order' => $row['sort_order'],
                        'is_active' => in_array($row['stage'], $activeStages, true),
                    ]
                );
                $levelIds[$row['code']] = $level->id;
                $levelIds[$row['stage']] = $level->id;
            }

            $streamIds = [];
            foreach ($streamSeed as $row) {
                // Unique is (school_id, name) on streams — match on name, set code
                $stream = Stream::query()->updateOrCreate(
                    ['school_id' => $school->id, 'name' => $row['name']],
                    [
                        'code' => $row['code'],
                        'is_active' => true,
                    ]
                );
                $streamIds[$row['code']] = $stream->id;
            }

            $year = AcademicYear::query()->updateOrCreate(
                ['school_id' => $school->id, 'name' => '2025/2026'],
                [
                    'starts_at' => '2025-01-15',
                    'ends_at' => '2025-12-15',
                    'is_current' => true,
                ]
            );

            // Ensure only one current year per school
            AcademicYear::query()
                ->where('school_id', $school->id)
                ->where('id', '!=', $year->id)
                ->update(['is_current' => false]);

            foreach (['Term 1', 'Term 2', 'Term 3'] as $i => $termName) {
                $starts = [1 => '2025-01-15', 2 => '2025-05-05', 3 => '2025-09-01'];
                $ends = [1 => '2025-04-15', 2 => '2025-08-15', 3 => '2025-12-15'];
                Term::query()->updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'academic_year_id' => $year->id,
                        'name' => $termName,
                    ],
                    [
                        'starts_at' => $starts[$i + 1],
                        'ends_at' => $ends[$i + 1],
                    ]
                );
            }

            foreach ($activeStages as $stage) {
                $classRows = $classesByStage[$stage] ?? [];
                $levelCode = EducationStages::levelCodeForStage($stage);
                $levelId = $levelCode ? ($levelIds[$levelCode] ?? null) : null;

                foreach ($classRows as $c) {
                    Classroom::query()->updateOrCreate(
                        [
                            'school_id' => $school->id,
                            'code' => $c['code'],
                        ],
                        [
                            'name' => $c['name'],
                            'education_level_id' => $levelId,
                            'stream_id' => $streamIds[$c['stream']] ?? null,
                            'capacity' => 45,
                            'is_active' => true,
                        ]
                    );
                }
            }

            $this->command?->info(
                "Academic structure: {$school->code} → stages [".implode(', ', $activeStages).']'
            );
        }
    }
}
