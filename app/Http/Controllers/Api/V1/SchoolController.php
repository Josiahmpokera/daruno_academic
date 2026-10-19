<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class SchoolController extends Controller
{
    use ApiResponds;

    public function index(Request $request)
    {
        try {
            $schools = School::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'slug', 'settings', 'created_at']);

            return $this->success([
                'schools' => $schools,
                'total' => $schools->count(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:50', 'unique:schools,code'],
                'slug' => ['nullable', 'string', 'max:100', 'unique:schools,slug'],
                'admin_name' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'admin_password' => ['required', 'string', 'min:8'],
            ]);

            $result = DB::transaction(function () use ($data) {
                $school = School::query()->create([
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'slug' => $data['slug'] ?? Str::slug($data['name']),
                    'settings' => [
                        'status' => 'pending',
                        'subscription' => [
                            'plan' => 'trial',
                            'billing_cycle' => 'monthly',
                        ],
                    ],
                ]);

                $role = Role::query()->where('name', 'school_admin')->first()
                    ?? Role::query()->where('name', 'school_owner')->firstOrFail();

                $admin = User::query()->create([
                    'school_id' => $school->id,
                    'role_id' => $role->id,
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => Hash::make($data['admin_password']),
                ]);

                return compact('school', 'admin');
            });

            return $this->success([
                'school' => [
                    'id' => $result['school']->id,
                    'name' => $result['school']->name,
                    'code' => $result['school']->code,
                    'slug' => $result['school']->slug,
                ],
                'admin' => [
                    'id' => $result['admin']->id,
                    'name' => $result['admin']->name,
                    'email' => $result['admin']->email,
                ],
            ], 'School created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, School $school)
    {
        try {
            if ($denied = $this->denyOtherSchool($request, $school)) {
                return $denied;
            }

            return $this->success([
                'school' => $school,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function update(Request $request, School $school)
    {
        try {
            if ($denied = $this->denyOtherSchool($request, $school)) {
                return $denied;
            }

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'settings' => ['sometimes', 'array'],
            ]);

            $school->fill($data);
            $school->save();

            return $this->success([
                'school' => $school->fresh(),
            ], 'School updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Tenant isolation: a school user may only see / edit the school they belong to.
     */
    protected function denyOtherSchool(Request $request, School $school)
    {
        $current = $request->attributes->get('school');
        if (TenantAccess::isPlatformAdmin($request->user()) && ! $current) {
            return null;
        }

        if (! $current || (int) $current->id !== (int) $school->id) {
            return $this->error('You do not have access to this school.', 403);
        }

        return null;
    }
}
