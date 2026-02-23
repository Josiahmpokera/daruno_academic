<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $roles = [
            ['name' => 'super_admin', 'label' => 'Super Admin'],
            ['name' => 'school_admin', 'label' => 'School Admin'],
            ['name' => 'teacher', 'label' => 'Teacher'],
            ['name' => 'accountant', 'label' => 'Accountant'],
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

