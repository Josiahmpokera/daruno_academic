<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Term;
use App\Support\EducationStages;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo learners: ≥30 active students per education level the school operates.
 * Respects school.education_stages — only those stages get students.
 */
class DemoStudentsSeeder extends Seeder
{
    public const PER_LEVEL = 32;

    /** @var list<string> */
    private array $firstMale = [
        'Juma', 'Amani', 'Baraka', 'Daniel', 'Emmanuel', 'Frank', 'Godfrey', 'Hassan',
        'Ibrahim', 'Joseph', 'Kelvin', 'Leonard', 'Michael', 'Nathan', 'Oscar', 'Peter',
        'Ramadhani', 'Samwel', 'Thomas', 'Victor', 'William', 'Yusuf', 'Zuberi', 'Brian',
        'Charles', 'Denis', 'Erick', 'Francis', 'George', 'Henry', 'Ismail', 'James',
    ];

    /** @var list<string> */
    private array $firstFemale = [
        'Amina', 'Beatrice', 'Christina', 'Diana', 'Esther', 'Fatuma', 'Grace', 'Halima',
        'Irene', 'Joyce', 'Kaite', 'Lilian', 'Maria', 'Neema', 'Oliver', 'Pendo',
        'Rehema', 'Salome', 'Tatu', 'Upendo', 'Vera', 'Winnie', 'Zainab', 'Asha',
        'Brenda', 'Consolata', 'Deborah', 'Eva', 'Flora', 'Gladness', 'Happy', 'Imani',
    ];

    /** @var list<string> */
    private array $middle = [
        'J.', 'M.', 'A.', 'S.', 'K.', 'P.', 'R.', 'D.', 'B.', 'N.',
    ];

    /** @var list<string> */
    private array $last = [
        'Mwangi', 'Kimaro', 'Msuya', 'Ngowi', 'Shirima', 'Massawe', 'Lyimo', 'Mrema',
        'Tarimo', 'Mushi', 'Kileo', 'Mollel', 'Sanga', 'Mbwambo', 'Kyara', 'Ngowi',
        'Mhando', 'Mtui', 'Swai', 'Moshi', 'Kaaya', 'Pallangyo', 'Maro', 'Minja',
        'Ngonyani', 'Mwakasege', 'Komba', 'Mapunda', 'Mgaya', 'Kapinga', 'Nyoni', 'Chuma',
    ];

    /** @var list<string> */
    private array $regions = [
        'Kilimanjaro', 'Arusha', 'Dar es Salaam', 'Mwanza', 'Mbeya', 'Dodoma',
        'Tanga', 'Morogoro', 'Iringa', 'Singida',
    ];

    /** @var list<string> */
    private array $religions = ['Christian', 'Muslim', 'Christian', 'Muslim', 'Other'];

    public function run(): void
    {
        $schools = School::query()->orderBy('id')->get();
        if ($schools->isEmpty()) {
            $this->command?->warn('DemoStudentsSeeder: no schools — run PlatformSeeder first.');

            return;
        }

        foreach ($schools as $school) {
            $this->seedSchool($school);
        }
    }

    protected function seedSchool(School $school): void
    {
        $offered = $school->offeredStages();
        $stages = $offered !== [] ? $offered : EducationStages::keys();

        $year = AcademicYear::query()
            ->where('school_id', $school->id)
            ->where('is_current', true)
            ->first()
            ?? AcademicYear::query()->where('school_id', $school->id)->orderByDesc('id')->first();

        if (! $year) {
            $this->command?->warn("DemoStudentsSeeder: {$school->code} has no academic year — skip.");

            return;
        }

        $term = Term::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->orderBy('id')
            ->first();

        $levels = EducationLevel::query()
            ->where('school_id', $school->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $totalCreated = 0;

        foreach ($levels as $level) {
            $stage = EducationStages::fromLevelCodeOrName($level->code, $level->name);
            if (! $stage || ! in_array($stage, $stages, true)) {
                continue;
            }

            $classrooms = Classroom::query()
                ->where('school_id', $school->id)
                ->where('education_level_id', $level->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            if ($classrooms->isEmpty()) {
                $this->command?->warn(
                    "DemoStudentsSeeder: {$school->code} level {$level->code} has no classes — skip."
                );

                continue;
            }

            $created = $this->seedLevel(
                $school,
                $level,
                $stage,
                $classrooms,
                $year,
                $term
            );
            $totalCreated += $created;
            $this->command?->info(
                "  {$school->code} · {$level->name} ({$stage}): {$created} students"
            );
        }

        $this->command?->info("Demo students for {$school->name}: {$totalCreated} learners (stages: ".implode(', ', $stages).')');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Classroom>  $classrooms
     */
    protected function seedLevel(
        School $school,
        EducationLevel $level,
        string $stage,
        $classrooms,
        AcademicYear $year,
        ?Term $term
    ): int {
        $prefix = sprintf(
            'DEMO/%s/%s',
            strtoupper($school->code),
            strtoupper($level->code ?: substr($stage, 0, 2))
        );

        // Count existing demo students for this level prefix
        $existing = Student::query()
            ->where('school_id', $school->id)
            ->where('admission_number', 'like', $prefix.'/%')
            ->count();

        $need = max(0, self::PER_LEVEL - $existing);
        if ($need === 0) {
            // Ensure enrollments exist for already-seeded demo rows
            $this->ensureEnrollments($school, $prefix, $classrooms, $year, $term);

            return $existing;
        }

        $classList = $classrooms->values();
        $classCount = $classList->count();
        $created = 0;

        DB::transaction(function () use (
            $school,
            $level,
            $stage,
            $classList,
            $classCount,
            $year,
            $term,
            $prefix,
            $existing,
            $need,
            &$created
        ) {
            for ($i = 0; $i < $need; $i++) {
                $seq = $existing + $i + 1;
                $admission = sprintf('%s/%03d', $prefix, $seq);

                // ~50/50 gender
                $isMale = ($seq % 2) === 1;
                $gender = $isMale ? 'male' : 'female';
                $firstPool = $isMale ? $this->firstMale : $this->firstFemale;
                $first = $firstPool[($seq - 1) % count($firstPool)];
                $middle = $this->middle[($seq - 1) % count($this->middle)];
                $last = $this->last[($seq + 3) % count($this->last)];
                $region = $this->regions[($seq - 1) % count($this->regions)];
                $religion = $this->religions[($seq - 1) % count($this->religions)];

                $dob = $this->dobForStage($stage, $seq);
                $classroom = $classList[$i % $classCount];

                $student = Student::query()->updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'admission_number' => $admission,
                    ],
                    [
                        'prems_number' => sprintf('PRM%s%04d', $school->code, $seq + ($level->id * 100)),
                        'first_name' => $first,
                        'middle_name' => $middle,
                        'last_name' => $last,
                        'date_of_birth' => $dob,
                        'birth_certificate_no' => sprintf('BC-%s-%04d', $school->code, $seq),
                        'place_of_birth' => $region,
                        'district_of_birth' => $region.' District',
                        'region_of_birth' => $region,
                        'gender' => $gender,
                        'nationality' => 'Tanzanian',
                        'religion' => $religion,
                        'blood_group' => ['O+', 'A+', 'B+', 'AB+', 'O-'][($seq - 1) % 5],
                        'phone' => sprintf('+2557%08d', 10000000 + ($school->id * 1000) + $seq),
                        'address_region' => $region,
                        'address_district' => $region.' District',
                        'address_ward' => 'Central',
                        'address_street' => 'Demo Street '.(($seq % 20) + 1),
                        'previous_school' => null,
                        'previous_class' => null,
                        'boarding_status' => $seq % 5 === 0 ? 'boarding' : 'day',
                        'admission_date' => '2025-01-15',
                        'status' => 'active',
                        'notes' => 'Demo student · '.$stage.' · '.$level->name,
                    ]
                );

                Enrollment::query()->updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'student_id' => $student->id,
                        'classroom_id' => $classroom->id,
                        'academic_year_id' => $year->id,
                        'term_id' => $term?->id,
                    ],
                    [
                        'enrolled_at' => '2025-01-15',
                        'status' => 'active',
                    ]
                );

                StudentGuardian::query()->updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'student_id' => $student->id,
                        'relationship' => 'parent',
                    ],
                    [
                        'full_name' => ($isMale ? 'Mr ' : 'Mrs ').$last.' Parent',
                        'phone' => sprintf('+2556%08d', 20000000 + ($school->id * 1000) + $seq),
                        'email' => strtolower(sprintf(
                            'parent.%s.%s@demo.school',
                            strtolower($school->code),
                            $seq
                        )),
                        'occupation' => ['Teacher', 'Farmer', 'Trader', 'Nurse', 'Driver'][($seq - 1) % 5],
                        'address' => $region,
                        'is_primary' => true,
                    ]
                );

                $created++;
            }
        });

        return $existing + $created;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Classroom>  $classrooms
     */
    protected function ensureEnrollments(
        School $school,
        string $prefix,
        $classrooms,
        AcademicYear $year,
        ?Term $term
    ): void {
        $students = Student::query()
            ->where('school_id', $school->id)
            ->where('admission_number', 'like', $prefix.'/%')
            ->orderBy('id')
            ->get();

        $classList = $classrooms->values();
        $classCount = max(1, $classList->count());

        foreach ($students as $i => $student) {
            $has = Enrollment::query()
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->exists();
            if ($has) {
                continue;
            }
            $classroom = $classList[$i % $classCount];
            Enrollment::query()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'classroom_id' => $classroom->id,
                'academic_year_id' => $year->id,
                'term_id' => $term?->id,
                'enrolled_at' => '2025-01-15',
                'status' => 'active',
            ]);
        }
    }

    protected function dobForStage(string $stage, int $seq): string
    {
        // Approximate ages for 2025 academic year
        $baseYear = match ($stage) {
            EducationStages::PRE_PRIMARY => 2020,
            EducationStages::PRIMARY => 2015,
            EducationStages::ORDINARY_LEVEL => 2010,
            EducationStages::ADVANCED_LEVEL => 2007,
            default => 2012,
        };
        $year = $baseYear - (($seq - 1) % 4);
        $month = (($seq - 1) % 12) + 1;
        $day = (($seq * 3) % 27) + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
