<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesTableSeeder::class,
        ]);

        // User::factory(10)->create();

        $parentRoleId = DB::table('roles')->where('name', 'parent')->value('id');

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role_id' => $parentRoleId,
        ]);
    }
}
