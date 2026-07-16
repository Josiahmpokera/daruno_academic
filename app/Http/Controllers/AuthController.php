<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\EducationStages;
use Database\Seeders\TamisemiSubjectsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            // Password is hashed by the User model cast — do not Hash::make here.
            'password' => $data['password'],
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
            // Multi-select TAMISEMI stages the school offers
            'education_stages' => ['required', 'array', 'min:1'],
            'education_stages.*' => ['required', 'string', Rule::in(EducationStages::keys())],
        ]);

        $stages = EducationStages::normalize($data['education_stages']);
        if ($stages === []) {
            return response()->json([
                'message' => 'Select at least one education level (e.g. Primary, O-Level).',
            ], 422);
        }

        return DB::transaction(function () use ($data, $stages) {
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
                'education_stages' => $stages,
                'education_level' => EducationStages::labelSummary($stages),
            ]);

            $role = Role::query()->where('name', 'school_admin')->firstOrFail();

            $admin = User::query()->create([
                'school_id' => $school->id,
                'role_id' => $role->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                // Hashed by User cast
                'password' => $data['admin_password'],
            ]);

            // Seed curriculum catalogue only for selected stages
            (new TamisemiSubjectsSeeder)->seedSchool((int) $school->id, $stages);

            $token = auth()->login($admin);

            return $this->respondWithToken($token, $admin, $school->fresh());
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
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'data' => [],
            ], 401);
        }

        $user->loadMissing('role', 'school');

        return response()->json([
            'success' => true,
            'error' => '',
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ]);
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

        $serialized = $this->serializeUser($user, $schoolOverride);
        $audience = ($serialized['role']['name'] ?? null) === 'super_admin'
            ? 'platform'
            : 'school';

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'audience' => $audience,
            'user' => $serialized,
        ]);
    }

    /**
     * Canonical user payload for login / me / refresh — never mix tenants.
     */
    protected function serializeUser(User $user, ?School $schoolOverride = null): array
    {
        $user->loadMissing('role', 'school');
        $school = $schoolOverride ?: $user->school;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'school_id' => $user->school_id,
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
                'education_stages' => $school->offeredStages(),
                'education_level' => $school->education_level
                    ?: EducationStages::labelSummary($school->offeredStages()),
            ] : null,
        ];
    }
}
