<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Idempotent demo pack: platform tenants + academic structure + sample students.
 * Prefer: php artisan db:seed --class=DemoDataSeeder
 * Or:     ./demo.sh
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesTableSeeder::class,
            SystemOwnerSeeder::class,
            PlatformSeeder::class,
            StaffSeeder::class,
            AcademicStructureSeeder::class,
            TamisemiSubjectsSeeder::class,
            DemoStudentsSeeder::class,
        ]);
    }
}
