<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $roles = [
            ['name' => 'super_admin', 'label' => 'Platform Super Admin'],
            ['name' => 'school_owner', 'label' => 'School Owner'],
            ['name' => 'school_admin', 'label' => 'School Admin'],
            ['name' => 'head_teacher', 'label' => 'Head Teacher'],
            ['name' => 'deputy_head', 'label' => 'Deputy Head'],
            ['name' => 'academic_master', 'label' => 'Academic Master'],
            ['name' => 'accountant', 'label' => 'Accountant'],
            ['name' => 'teacher', 'label' => 'Teacher'],
            ['name' => 'librarian', 'label' => 'Librarian'],
            ['name' => 'store_keeper', 'label' => 'Store Keeper'],
            ['name' => 'hostel_warden', 'label' => 'Hostel Warden'],
            ['name' => 'parent', 'label' => 'Parent'],
            ['name' => 'student', 'label' => 'Student'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'label' => $role['label'],
                    'permissions' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
