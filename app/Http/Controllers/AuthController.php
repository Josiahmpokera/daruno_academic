<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = auth()->login($user);

        return $this->respondWithToken($token, $user);
    }

    public function registerSchool(Request $request)
    {
        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'school_code' => ['nullable', 'string', 'max:50', 'unique:schools,code'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        return DB::transaction(function () use ($data) {
            $code = $data['school_code'] ?? null;

            if (! $code) {
                do {
                    $code = strtoupper(Str::random(6));
                } while (School::query()->where('code', $code)->exists());
            }

            $slugBase = Str::slug($data['school_name']);
            $slug = $slugBase ?: Str::lower($code);

            if (School::query()->where('slug', $slug)->exists()) {
                $slug = $slug.'-'.Str::random(4);
            }

            $school = School::query()->create([
                'name' => $data['school_name'],
                'code' => $code,
                'slug' => $slug,
            ]);

            $role = Role::query()->where('name', 'school_admin')->firstOrFail();

            $admin = User::query()->create([
                'school_id' => $school->id,
                'role_id' => $role->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
            ]);

            $token = auth()->login($admin);

            return $this->respondWithToken($token, $admin, $school);
        });
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $token = auth()->attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = auth()->user();

        return $this->respondWithToken($token, $user);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout()
    {
        auth()->logout();

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    public function refresh()
    {
        $token = auth()->refresh();
        $user = auth()->user();

        return $this->respondWithToken($token, $user);
    }

    protected function respondWithToken(string $token, ?User $user = null, ?School $schoolOverride = null)
    {
        if (! $user) {
            $user = auth()->user();
        }

        $user->loadMissing('role', 'school');

        $school = $schoolOverride ?: $user->school;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'label' => $user->role->label,
                ] : null,
                'school' => $school ? [
                    'id' => $school->id,
                    'name' => $school->name,
                    'code' => $school->code,
                    'slug' => $school->slug,
                ] : null,
            ],
        ]);
    }
}
