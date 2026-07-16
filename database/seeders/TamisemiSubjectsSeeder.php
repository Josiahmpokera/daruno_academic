<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Subject;
use App\Models\SubjectCategory;
use App\Models\SubjectCombination;
use Illuminate\Database\Seeder;

/**
 * Seeds TAMISEMI / NECTA-oriented subject categories, subjects, and
 * advanced-level combinations for each school (idempotent).
 */
class TamisemiSubjectsSeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::query()->get();
        if ($schools->isEmpty()) {
            return;
        }

        foreach ($schools as $school) {
            $stages = method_exists($school, 'offeredStages')
                ? $school->offeredStages()
                : [];
            $this->seedSchool((int) $school->id, $stages ?: null);
        }
    }

    /**
     * @param  list<string>|null  $stages  When set, only seed subjects/combos for these stages (+ shared).
     */
    public function seedSchool(int $schoolId, ?array $stages = null): void
    {
        $stages = $stages ? array_values(array_unique($stages)) : null;

        $categories = [
            ['name' => 'Languages', 'code' => 'LANG', 'sort_order' => 1, 'description' => 'Kiswahili, English and other languages'],
            ['name' => 'Mathematics', 'code' => 'MATH', 'sort_order' => 2, 'description' => 'Mathematics and related'],
            ['name' => 'Sciences', 'code' => 'SCI', 'sort_order' => 3, 'description' => 'Natural sciences'],
            ['name' => 'Social sciences', 'code' => 'SOC', 'sort_order' => 4, 'description' => 'History, Geography, Civics'],
            ['name' => 'Business studies', 'code' => 'BUS', 'sort_order' => 5, 'description' => 'Commerce, Book-keeping, Economics'],
            ['name' => 'Vocational & skills', 'code' => 'VOC', 'sort_order' => 6, 'description' => 'Practical and vocational subjects'],
            ['name' => 'ICT', 'code' => 'ICT', 'sort_order' => 7, 'description' => 'Information and communication technology'],
            ['name' => 'Arts & sports', 'code' => 'ART', 'sort_order' => 8, 'description' => 'Arts, music, sports and culture'],
            ['name' => 'General studies', 'code' => 'GS', 'sort_order' => 9, 'description' => 'General studies / life skills'],
        ];

        $catIds = [];
        foreach ($categories as $row) {
            $cat = SubjectCategory::query()->updateOrCreate(
                ['school_id' => $schoolId, 'name' => $row['name']],
                [
                    'code' => $row['code'],
                    'description' => $row['description'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ]
            );
            $catIds[$row['code']] = $cat->id;
        }

        // code => [name, short, category, stage, core, compulsory, periods, sort, necta optional]
        $subjects = [
            // Primary + Pre-primary core (TAMISEMI primary curriculum)
            ['Kiswahili', 'KIS', 'KIS', 'LANG', 'primary', true, true, 6, 1, null],
            ['English', 'ENG', 'ENG', 'LANG', 'primary', true, true, 5, 2, null],
            ['Mathematics', 'MATH', 'MATH', 'MATH', 'primary', true, true, 6, 3, null],
            ['Science and Technology', 'S&T', 'ST', 'SCI', 'primary', true, true, 4, 4, null],
            ['Social Studies', 'SST', 'SST', 'SOC', 'primary', true, true, 3, 5, null],
            ['Civic and Moral Education', 'CIV', 'CME', 'SOC', 'primary', true, true, 2, 6, null],
            ['Vocational Skills', 'VOC', 'VS', 'VOC', 'primary', true, true, 2, 7, null],
            ['Information and Computer Studies', 'ICS', 'ICS', 'ICT', 'primary', false, false, 2, 8, null],
            ['French', 'FRE', 'FRE', 'LANG', 'primary', false, false, 2, 9, null],
            ['Arabic', 'ARA', 'ARA', 'LANG', 'primary', false, false, 2, 10, null],
            ['Sports and Games', 'SPT', 'SPT', 'ART', 'primary', true, true, 2, 11, null],
            ['Arts and Crafts', 'ART', 'ART', 'ART', 'primary', false, false, 2, 12, null],

            // O-Level (NECTA CSEE)
            ['Biology', 'BIO', 'BIO', 'SCI', 'ordinary_level', true, true, 4, 20, '012'],
            ['Chemistry', 'CHEM', 'CHE', 'SCI', 'ordinary_level', true, false, 4, 21, '031'],
            ['Physics', 'PHY', 'PHY', 'SCI', 'ordinary_level', true, false, 4, 22, '033'],
            ['Geography', 'GEO', 'GEO', 'SOC', 'ordinary_level', true, true, 3, 23, '013'],
            ['History', 'HIST', 'HIS', 'SOC', 'ordinary_level', true, true, 3, 24, '012'],
            ['Civics', 'CIVS', 'CIV', 'SOC', 'ordinary_level', true, true, 2, 25, '011'],
            ['Book Keeping', 'BK', 'BK', 'BUS', 'ordinary_level', false, false, 3, 26, '062'],
            ['Commerce', 'COM', 'COM', 'BUS', 'ordinary_level', false, false, 3, 27, '061'],
            ['Agriculture', 'AGR', 'AGR', 'VOC', 'ordinary_level', false, false, 3, 28, '034'],
            ['Computer Studies', 'CS', 'CS', 'ICT', 'ordinary_level', false, false, 3, 29, '036'],
            ['Literature in English', 'LIT', 'LIT', 'LANG', 'ordinary_level', false, false, 3, 30, '022'],
            ['Additional Mathematics', 'AMATH', 'AM', 'MATH', 'ordinary_level', false, false, 3, 31, '041'],
            ['Basic Mathematics', 'BMATH', 'BM', 'MATH', 'ordinary_level', true, true, 5, 32, '041'],
            ['English Language', 'EL', 'EL', 'LANG', 'ordinary_level', true, true, 4, 33, '022'],
            ['Kiswahili Language', 'KL', 'KL', 'LANG', 'ordinary_level', true, true, 4, 34, '021'],

            // A-Level principals (NECTA ACSEE) — reuse codes where distinct
            ['Advanced Mathematics', 'ADVM', 'ADVM', 'MATH', 'advanced_level', true, true, 8, 40, '131'],
            ['Advanced Physics', 'ADVP', 'ADVP', 'SCI', 'advanced_level', true, true, 8, 41, '133'],
            ['Advanced Chemistry', 'ADVC', 'ADVC', 'SCI', 'advanced_level', true, true, 8, 42, '132'],
            ['Advanced Biology', 'ADVB', 'ADVB', 'SCI', 'advanced_level', true, true, 8, 43, '134'],
            ['Advanced Geography', 'ADVG', 'ADVG', 'SOC', 'advanced_level', true, true, 6, 44, '113'],
            ['Advanced History', 'ADVH', 'ADVH', 'SOC', 'advanced_level', true, true, 6, 45, '112'],
            ['Economics', 'ECON', 'ECO', 'BUS', 'advanced_level', true, true, 6, 46, '151'],
            ['Accountancy', 'ACC', 'ACC', 'BUS', 'advanced_level', true, true, 6, 47, '152'],
            ['General Studies', 'GS', 'GS', 'GS', 'advanced_level', true, true, 2, 48, '111'],
            ['Basic Applied Mathematics', 'BAM', 'BAM', 'MATH', 'advanced_level', true, false, 4, 49, '131'],
        ];

        $subjectIds = [];
        foreach ($subjects as $i => $s) {
            [$name, $short, $code, $catCode, $stage, $core, $compulsory, $periods, $sort, $necta] = $s;

            // Skip subjects outside the school's offered stages
            if ($stages !== null && $stage !== 'all' && ! in_array($stage, $stages, true)) {
                continue;
            }

            $subject = Subject::query()->updateOrCreate(
                ['school_id' => $schoolId, 'code' => $code],
                [
                    'name' => $name,
                    'short_name' => $short,
                    'subject_category_id' => $catIds[$catCode] ?? null,
                    'necta_code' => $necta,
                    'education_stage' => $stage,
                    'is_core' => $core,
                    'is_compulsory' => $compulsory,
                    'is_active' => true,
                    'weekly_periods' => $periods,
                    'sort_order' => $sort,
                ]
            );
            $subjectIds[$code] = $subject->id;
        }

        // NECTA-style A-level combinations (principals)
        if ($stages !== null && ! in_array('advanced_level', $stages, true)) {
            return;
        }

        $combos = [
            ['PCM', 'Physics, Chemistry & Mathematics', ['ADVP', 'ADVC', 'ADVM'], 'Science combination (STEM)'],
            ['PCB', 'Physics, Chemistry & Biology', ['ADVP', 'ADVC', 'ADVB'], 'Science combination (medical path)'],
            ['PGM', 'Physics, Geography & Mathematics', ['ADVP', 'ADVG', 'ADVM'], 'Science / applied geography'],
            ['CBG', 'Chemistry, Biology & Geography', ['ADVC', 'ADVB', 'ADVG'], 'Science combination'],
            ['EGM', 'Economics, Geography & Mathematics', ['ECON', 'ADVG', 'ADVM'], 'Business / social science'],
            ['ECA', 'Economics, Commerce & Accountancy', ['ECON', 'COM', 'ACC'], 'Business combination'],
            ['HGL', 'History, Geography & Language', ['ADVH', 'ADVG', 'EL'], 'Arts combination'],
            ['HKL', 'History, Kiswahili & English Language', ['ADVH', 'KL', 'EL'], 'Arts / language combination'],
            ['HGK', 'History, Geography & Kiswahili', ['ADVH', 'ADVG', 'KL'], 'Arts combination'],
        ];

        foreach ($combos as $combo) {
            [$code, $name, $codes, $desc] = $combo;
            $combination = SubjectCombination::query()->updateOrCreate(
                ['school_id' => $schoolId, 'code' => $code],
                [
                    'name' => $name,
                    'education_stage' => 'advanced_level',
                    'description' => $desc,
                    'is_active' => true,
                ]
            );

            $sync = [];
            foreach ($codes as $order => $subjCode) {
                if (! isset($subjectIds[$subjCode])) {
                    continue;
                }
                $sync[$subjectIds[$subjCode]] = [
                    'is_principal' => true,
                    'sort_order' => $order + 1,
                ];
            }
            // Attach General Studies as non-principal where present
            if (isset($subjectIds['GS'])) {
                $sync[$subjectIds['GS']] = [
                    'is_principal' => false,
                    'sort_order' => 99,
                ];
            }
            $combination->subjects()->sync($sync);
        }
    }
}
