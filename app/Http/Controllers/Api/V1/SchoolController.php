<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SchoolController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:schools,code'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:schools,slug'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        return DB::transaction(function () use ($data) {
            $school = School::query()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'slug' => $data['slug'] ?? null,
            ]);

            $role = Role::query()->where('name', 'school_admin')->firstOrFail();

            $admin = User::query()->create([
                'school_id' => $school->id,
                'role_id' => $role->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
            ]);

            return response()->json([
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'code' => $school->code,
                    'slug' => $school->slug,
                ],
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ],
            ], 201);
        });
    }
}

