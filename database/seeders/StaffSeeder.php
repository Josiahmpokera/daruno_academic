<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\School;
use App\Models\Staff;
use App\Models\StaffPosition;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::query()->get();
        if ($schools->isEmpty()) {
            return;
        }

        $deptSeed = [
            ['name' => 'Administration', 'code' => 'ADMIN'],
            ['name' => 'Finance', 'code' => 'FIN'],
            ['name' => 'Operations', 'code' => 'OPS'],
            ['name' => 'Health', 'code' => 'HLTH'],
            ['name' => 'Security', 'code' => 'SEC'],
        ];

        $posSeed = [
            ['name' => 'Admin Assistant', 'code' => 'ADM_ASST'],
            ['name' => 'Accountant', 'code' => 'ACCT'],
            ['name' => 'Store Keeper', 'code' => 'STORE'],
            ['name' => 'Nurse', 'code' => 'NURSE'],
            ['name' => 'Security Officer', 'code' => 'SEC_OFF'],
            ['name' => 'Librarian', 'code' => 'LIB'],
        ];

        foreach ($schools as $school) {
            $deptIds = [];
            foreach ($deptSeed as $d) {
                $dept = Department::query()->updateOrCreate(
                    ['school_id' => $school->id, 'code' => $d['code']],
                    [
                        'name' => $d['name'],
                        'description' => $d['name'].' department',
                        'is_active' => true,
                    ]
                );
                $deptIds[$d['code']] = $dept->id;
            }

            $posIds = [];
            foreach ($posSeed as $p) {
                $pos = StaffPosition::query()->updateOrCreate(
                    ['school_id' => $school->id, 'code' => $p['code']],
                    [
                        'name' => $p['name'],
                        'description' => $p['name'].' role',
                        'is_active' => true,
                    ]
                );
                $posIds[$p['code']] = $pos->id;
            }

            if (Staff::query()->where('school_id', $school->id)->exists()) {
                continue;
            }

            $samples = [
                [
                    'first_name' => 'David',
                    'last_name' => 'Wilson',
                    'email' => 'david.wilson@'.$school->code.'.school',
                    'phone' => '+255700100201',
                    'dept' => 'ADMIN',
                    'pos' => 'ADM_ASST',
                    'employment_type' => 'Full-time',
                    'status' => 'active',
                    'code' => 'STF-0001',
                ],
                [
                    'first_name' => 'Amina',
                    'last_name' => 'Hassan',
                    'email' => 'amina.hassan@'.$school->code.'.school',
                    'phone' => '+255700100202',
                    'dept' => 'FIN',
                    'pos' => 'ACCT',
                    'employment_type' => 'Full-time',
                    'status' => 'active',
                    'code' => 'STF-0002',
                ],
                [
                    'first_name' => 'John',
                    'last_name' => 'Mwamba',
                    'email' => 'john.mwamba@'.$school->code.'.school',
                    'phone' => '+255700100203',
                    'dept' => 'OPS',
                    'pos' => 'STORE',
                    'employment_type' => 'Contract',
                    'status' => 'active',
                    'code' => 'STF-0003',
                ],
                [
                    'first_name' => 'Grace',
                    'last_name' => 'Kimaro',
                    'email' => 'grace.kimaro@'.$school->code.'.school',
                    'phone' => '+255700100204',
                    'dept' => 'HLTH',
                    'pos' => 'NURSE',
                    'employment_type' => 'Part-time',
                    'status' => 'on_leave',
                    'code' => 'STF-0004',
                ],
                [
                    'first_name' => 'Peter',
                    'last_name' => 'Okello',
                    'email' => 'peter.okello@'.$school->code.'.school',
                    'phone' => '+255700100205',
                    'dept' => 'SEC',
                    'pos' => 'SEC_OFF',
                    'employment_type' => 'Full-time',
                    'status' => 'inactive',
                    'code' => 'STF-0005',
                ],
            ];

            foreach ($samples as $row) {
                Staff::query()->create([
                    'school_id' => $school->id,
                    'employee_code' => $row['code'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'department_id' => $deptIds[$row['dept']] ?? null,
                    'staff_position_id' => $posIds[$row['pos']] ?? null,
                    'position' => $posSeed[array_search($row['pos'], array_column($posSeed, 'code'), true)]['name'] ?? null,
                    'employment_type' => $row['employment_type'],
                    'hire_date' => now()->subMonths(rand(3, 24))->toDateString(),
                    'status' => $row['status'],
                    'user_id' => null,
                ]);
            }
        }
    }
}
