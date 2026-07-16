<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SystemOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if (! $roleId) {
            return;
        }

        $email = 'owner@daruno.app';

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        User::query()->create([
            'school_id' => null,
            'role_id' => $roleId,
            'name' => 'Shengena Owner',
            'email' => $email,
            'password' => Hash::make('Daruno123!'),
        ]);
    }
}

