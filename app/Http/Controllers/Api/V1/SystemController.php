<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\LoginHistory;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Staff;
use App\Models\StockItem;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * School tenant System hub: reports, users/RBAC, TAMISEMI compliance, admin.
 */
class SystemController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Reports ───────────────────────────────────────────────────

    public function reportsOverview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);

            return $this->success([
                'summary' => [
                    'students' => Student::query()->where('school_id', $schoolId)->count(),
                    'students_active' => Student::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
                    'staff' => Staff::query()->where('school_id', $schoolId)->count(),
                    'users' => User::query()->where('school_id', $schoolId)->count(),
                    'outstanding_fees' => round((float) FeeInvoice::query()
                        ->where('school_id', $schoolId)->where('balance_amount', '>', 0)->sum('balance_amount'), 2),
                    'collections_mtd' => round((float) FeePayment::query()
                        ->where('school_id', $schoolId)
                        ->where('status', 'completed')
                        ->whereMonth('paid_at', now()->month)
                        ->whereYear('paid_at', now()->year)
                        ->sum('amount'), 2),
                    'attendance_today' => AttendanceRecord::query()
                        ->where('school_id', $schoolId)
                        ->whereDate('date', now()->toDateString())
                        ->count(),
                    'open_homework' => Assignment::query()
                        ->where('school_id', $schoolId)
                        ->where('status', 'published')
                        ->count(),
                    'low_stock' => StockItem::query()
                        ->where('school_id', $schoolId)
                        ->where('is_active', true)
                        ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                        ->count(),
                ],
                'by_status' => Student::query()
                    ->where('school_id', $schoolId)
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->get(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsStudents(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $byClass = Enrollment::query()
                ->where('enrollments.school_id', $schoolId)
                ->where('enrollments.status', 'active')
                ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
                ->selectRaw('classrooms.name as classroom, count(*) as total')
                ->groupBy('classrooms.id', 'classrooms.name')
                ->orderBy('classrooms.name')
                ->get();

            $byGender = Student::query()
                ->where('school_id', $schoolId)
                ->where('status', 'active')
                ->selectRaw('gender, count(*) as total')
                ->groupBy('gender')
                ->get();

            return $this->success([
                'by_class' => $byClass,
                'by_gender' => $byGender,
                'total_active' => Student::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
                'total' => Student::query()->where('school_id', $schoolId)->count(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsAttendance(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $from = $request->date('from') ?: now()->startOfMonth()->toDateString();
            $to = $request->date('to') ?: now()->toDateString();

            $byStatus = AttendanceRecord::query()
                ->where('school_id', $schoolId)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->get();

            $days = AttendanceRecord::query()
                ->where('school_id', $schoolId)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->selectRaw('date, count(*) as marks')
                ->groupBy('date')
                ->orderBy('date')
                ->limit(60)
                ->get();

            return $this->success([
                'period' => ['from' => $from, 'to' => $to],
                'by_status' => $byStatus,
                'by_day' => $days,
                'total_marks' => (int) $byStatus->sum('total'),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsFinance(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $from = $request->date('from') ?: now()->startOfMonth()->toDateString();
            $to = $request->date('to') ?: now()->toDateString();

            $collections = (float) FeePayment::query()
                ->where('school_id', $schoolId)->where('status', 'completed')
                ->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to)
                ->sum('amount');
            $billed = (float) FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)
                ->sum('total_amount');
            $outstanding = (float) FeeInvoice::query()
                ->where('school_id', $schoolId)->where('balance_amount', '>', 0)
                ->sum('balance_amount');

            return $this->success([
                'period' => ['from' => $from, 'to' => $to],
                'summary' => [
                    'billed' => round($billed, 2),
                    'collections' => round($collections, 2),
                    'outstanding' => round($outstanding, 2),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsTeachers(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $byStatus = Staff::query()
                ->where('school_id', $schoolId)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->get();

            return $this->success([
                'total' => Staff::query()->where('school_id', $schoolId)->count(),
                'by_status' => $byStatus,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsInventory(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $value = (float) StockItem::query()
                ->where('school_id', $schoolId)
                ->selectRaw('COALESCE(SUM(quantity_on_hand * unit_cost),0) as v')
                ->value('v');
            $low = StockItem::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                ->count();

            return $this->success([
                'summary' => [
                    'stock_items' => StockItem::query()->where('school_id', $schoolId)->count(),
                    'stock_value' => round($value, 2),
                    'low_stock' => $low,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function reportsExams(Request $request)
    {
        try {
            // Lightweight: assessment counts if table exists path via Assessment model if present
            $schoolId = $this->schoolId($request);
            $count = 0;
            if (class_exists(\App\Models\Assessment::class)) {
                $count = \App\Models\Assessment::query()->where('school_id', $schoolId)->count();
            }

            return $this->success([
                'summary' => [
                    'assessments' => $count,
                    'note' => 'Use Examinations module for full result analytics.',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Users & roles ─────────────────────────────────────────────

    public function usersIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureSchoolRolesAndPermissions();

            $q = User::query()
                ->where('school_id', $schoolId)
                ->with('role:id,name,label,scope')
                ->orderBy('name');

            $search = $request->string('q')->toString();
            if ($search !== '') {
                $q->where(function ($b) use ($search) {
                    $b->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            if ($request->integer('role_id')) {
                $q->where('role_id', $request->integer('role_id'));
            }

            $page = $q->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'users' => collect($page->items())->map(fn (User $u) => $this->transformUser($u)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function usersStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureSchoolRolesAndPermissions();
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
                'phone' => ['nullable', 'string', 'max:40'],
                'role_id' => ['required', 'integer'],
                'password' => ['nullable', 'string', 'min:8'],
            ]);

            $role = Role::query()->findOrFail($data['role_id']);
            if (($role->name ?? '') === 'super_admin') {
                return $this->error('Cannot assign platform super admin to a school user', 422);
            }

            $password = $data['password'] ?? Str::password(12);
            $user = User::query()->create([
                'school_id' => $schoolId,
                'role_id' => $role->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($password),
            ]);

            $this->audit($request, 'user.created', 'user', $user->id, ['email' => $user->email]);

            return $this->success([
                'user' => $this->transformUser($user->load('role')),
                'temporary_password' => empty($data['password']) ? $password : null,
            ], 'User created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function usersUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $user = User::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'email' => ['sometimes', 'email', 'max:190', 'unique:users,email,'.$id],
                'phone' => ['nullable', 'string', 'max:40'],
                'role_id' => ['sometimes', 'integer'],
                'password' => ['nullable', 'string', 'min:8'],
            ]);

            if (! empty($data['role_id'])) {
                $role = Role::query()->findOrFail($data['role_id']);
                if (($role->name ?? '') === 'super_admin') {
                    return $this->error('Cannot assign platform super admin to a school user', 422);
                }
            }
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            $user->fill($data)->save();
            $this->audit($request, 'user.updated', 'user', $user->id, array_keys($data));

            return $this->success(['user' => $this->transformUser($user->fresh()->load('role'))], 'User updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function rolesIndex(Request $request)
    {
        try {
            $this->ensureSchoolRolesAndPermissions();
            $scope = $request->string('scope')->toString();
            $schoolId = $this->schoolId($request);
            $q = Role::query()->where('name', '!=', 'super_admin')->orderBy('label');
            if ($scope !== '') {
                $q->where('scope', $scope);
            } else {
                $q->whereIn('scope', ['school', 'family']);
            }
            $roles = $q->get()->map(function (Role $r) use ($schoolId) {
                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'label' => $r->label ?: $r->name,
                    'scope' => $r->scope ?? 'school',
                    'is_system' => (bool) $r->is_system,
                    'permissions' => $r->permissions ?? [],
                    'users_count' => User::query()->where('school_id', $schoolId)->where('role_id', $r->id)->count(),
                    'editable' => false,
                ];
            });

            return $this->success(['roles' => $roles]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function rolesUpdate(Request $request, int $id)
    {
        try {
            // Roles are shared by every school: a school editing one would change
            // it for all tenants. Only the platform owner may edit role definitions.
            if (! TenantAccess::isPlatformAdmin($request->user())) {
                return $this->error('Roles are shared across schools and can only be changed by Shengena platform admins.', 403);
            }
            $role = Role::query()->findOrFail($id);
            if (($role->name ?? '') === 'super_admin') {
                return $this->error('Platform role cannot be edited here', 422);
            }
            $data = $request->validate([
                'label' => ['sometimes', 'string', 'max:120'],
                'permissions' => ['sometimes', 'array'],
                'permissions.*' => ['string'],
            ]);
            $role->fill($data)->save();
            $this->audit($request, 'role.updated', 'role', $role->id, $data);

            return $this->success(['role' => $role->fresh()], 'Role updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function permissionsIndex()
    {
        try {
            $this->ensureSchoolRolesAndPermissions();
            $items = Permission::query()->orderBy('module')->orderBy('name')->get()
                ->groupBy('module');

            return $this->success([
                'permissions' => Permission::query()->orderBy('module')->orderBy('name')->get(),
                'by_module' => $items,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function auditIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = AuditLog::query()
                ->where('school_id', $schoolId)
                ->with('user:id,name,email')
                ->orderByDesc('id');

            if ($request->string('action')->toString() !== '') {
                $q->where('action', 'like', '%'.$request->string('action')->toString().'%');
            }

            $page = $q->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'logs' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function loginHistoryIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $page = LoginHistory::query()
                ->where('school_id', $schoolId)
                ->with('user:id,name,email')
                ->orderByDesc('logged_in_at')
                ->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'history' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function passwordPolicyShow(Request $request)
    {
        try {
            $settings = $this->settingsMap($this->schoolId($request));

            return $this->success([
                'policy' => [
                    'min_length' => (int) ($settings['password_min_length'] ?? 8),
                    'require_uppercase' => (bool) ($settings['password_require_uppercase'] ?? true),
                    'require_number' => (bool) ($settings['password_require_number'] ?? true),
                    'require_symbol' => (bool) ($settings['password_require_symbol'] ?? false),
                    'expiry_days' => (int) ($settings['password_expiry_days'] ?? 0),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function passwordPolicyUpdate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'min_length' => ['sometimes', 'integer', 'min:6', 'max:64'],
                'require_uppercase' => ['sometimes', 'boolean'],
                'require_number' => ['sometimes', 'boolean'],
                'require_symbol' => ['sometimes', 'boolean'],
                'expiry_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            ]);
            $map = [
                'min_length' => 'password_min_length',
                'require_uppercase' => 'password_require_uppercase',
                'require_number' => 'password_require_number',
                'require_symbol' => 'password_require_symbol',
                'expiry_days' => 'password_expiry_days',
            ];
            foreach ($data as $k => $v) {
                $this->putSetting($schoolId, $map[$k], is_bool($v) ? ($v ? '1' : '0') : (string) $v, gettype($v));
            }
            $this->audit($request, 'security.password_policy', 'settings', null, $data);

            return $this->passwordPolicyShow($request);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function twoFactorShow(Request $request)
    {
        try {
            $settings = $this->settingsMap($this->schoolId($request));

            return $this->success([
                'two_factor' => [
                    'enabled' => (bool) ($settings['2fa_enabled'] ?? false),
                    'required_for_admins' => (bool) ($settings['2fa_required_admins'] ?? false),
                    'methods' => ['authenticator', 'email'],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function twoFactorUpdate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'enabled' => ['sometimes', 'boolean'],
                'required_for_admins' => ['sometimes', 'boolean'],
            ]);
            if (array_key_exists('enabled', $data)) {
                $this->putSetting($schoolId, '2fa_enabled', $data['enabled'] ? '1' : '0', 'boolean');
            }
            if (array_key_exists('required_for_admins', $data)) {
                $this->putSetting($schoolId, '2fa_required_admins', $data['required_for_admins'] ? '1' : '0', 'boolean');
            }
            $this->audit($request, 'security.2fa', 'settings', null, $data);

            return $this->twoFactorShow($request);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Compliance (TAMISEMI / NECTA style) ───────────────────────

    public function complianceOverview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $school = $this->school($request);

            return $this->success([
                'school' => [
                    'name' => $school->name,
                    'code' => $school->code,
                    'emis_code' => $school->emis_code,
                    'registration_number' => $school->registration_number,
                    'region' => $school->region,
                    'district' => $school->district,
                    'education_stages' => $school->offeredStages(),
                ],
                'summary' => [
                    'enrollment_active' => Student::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
                    'staff' => Staff::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
                    'classes' => \App\Models\Classroom::query()->where('school_id', $schoolId)->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function complianceCensus(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $school = $this->school($request);

            $byLevelGender = Enrollment::query()
                ->where('enrollments.school_id', $schoolId)
                ->where('enrollments.status', 'active')
                ->join('students', 'students.id', '=', 'enrollments.student_id')
                ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
                ->leftJoin('education_levels', 'education_levels.id', '=', 'classrooms.education_level_id')
                ->selectRaw("COALESCE(education_levels.name, 'Unassigned') as level, students.gender, count(*) as total")
                ->groupBy('level', 'students.gender')
                ->get();

            return $this->success([
                'as_of' => now()->toDateString(),
                'school' => [
                    'name' => $school->name,
                    'emis_code' => $school->emis_code,
                    'region' => $school->region,
                    'district' => $school->district,
                ],
                'rows' => $byLevelGender,
                'total' => Student::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function complianceEnrollment(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $rows = Enrollment::query()
                ->where('enrollments.school_id', $schoolId)
                ->where('enrollments.status', 'active')
                ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
                ->join('students', 'students.id', '=', 'enrollments.student_id')
                ->selectRaw("classrooms.name as classroom,
                    sum(case when students.gender in ('Male','male','M','m') then 1 else 0 end) as boys,
                    sum(case when students.gender in ('Female','female','F','f') then 1 else 0 end) as girls,
                    count(*) as total")
                ->groupBy('classrooms.id', 'classrooms.name')
                ->orderBy('classrooms.name')
                ->get();

            return $this->success([
                'rows' => $rows,
                'totals' => [
                    'boys' => (int) $rows->sum('boys'),
                    'girls' => (int) $rows->sum('girls'),
                    'total' => (int) $rows->sum('total'),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function complianceProfile(Request $request)
    {
        try {
            $school = $this->school($request);

            return $this->success([
                'profile' => [
                    'name' => $school->name,
                    'code' => $school->code,
                    'emis_code' => $school->emis_code,
                    'registration_number' => $school->registration_number,
                    'ownership' => $school->ownership,
                    'school_type' => $school->school_type,
                    'education_level' => $school->education_level,
                    'education_stages' => $school->offeredStages(),
                    'region' => $school->region,
                    'district' => $school->district,
                    'city' => $school->city,
                    'address' => $school->address,
                    'contact_email' => $school->contact_email,
                    'contact_phone' => $school->contact_phone,
                    'motto' => $school->motto,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function complianceExport(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $type = $request->string('type')->toString() ?: 'enrollment';

            if ($type === 'students') {
                $rows = Student::query()
                    ->where('school_id', $schoolId)
                    ->with('currentEnrollment.classroom:id,name')
                    ->orderBy('admission_number')
                    ->get()
                    ->map(fn (Student $s) => [
                        'admission_number' => $s->admission_number,
                        'prems_number' => $s->prems_number,
                        'full_name' => $s->full_name,
                        'gender' => $s->gender,
                        'date_of_birth' => optional($s->date_of_birth)?->toDateString(),
                        'status' => $s->status,
                        'classroom' => $s->currentEnrollment?->classroom?->name,
                        'region' => $s->address_region,
                        'district' => $s->address_district,
                    ]);
            } elseif ($type === 'staff') {
                $rows = Staff::query()->where('school_id', $schoolId)->orderBy('last_name')->get()
                    ->map(fn (Staff $s) => [
                        'employee_code' => $s->employee_code,
                        'full_name' => $s->full_name,
                        'position' => $s->position,
                        'employment_type' => $s->employment_type,
                        'status' => $s->status,
                        'phone' => $s->phone,
                        'email' => $s->email,
                    ]);
            } else {
                return $this->complianceEnrollment($request);
            }

            return $this->success([
                'type' => $type,
                'exported_at' => now()->toIso8601String(),
                'rows' => $rows,
                'count' => $rows->count(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Administration ────────────────────────────────────────────

    public function adminSettingsShow(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $map = $this->settingsMap($schoolId);

            return $this->success([
                'settings' => [
                    'school_display_name' => $map['school_display_name'] ?? $this->school($request)->name,
                    'timezone' => $map['timezone'] ?? 'Africa/Dar_es_Salaam',
                    'locale' => $map['locale'] ?? 'sw',
                    'currency' => $map['currency'] ?? 'TZS',
                    'academic_year_label' => $map['academic_year_label'] ?? '',
                    'allow_parent_portal' => (bool) ($map['allow_parent_portal'] ?? true),
                    'allow_teacher_portal' => (bool) ($map['allow_teacher_portal'] ?? true),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function adminSettingsUpdate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'school_display_name' => ['sometimes', 'string', 'max:160'],
                'timezone' => ['sometimes', 'string', 'max:60'],
                'locale' => ['sometimes', 'string', 'max:10'],
                'currency' => ['sometimes', 'string', 'size:3'],
                'academic_year_label' => ['nullable', 'string', 'max:40'],
                'allow_parent_portal' => ['sometimes', 'boolean'],
                'allow_teacher_portal' => ['sometimes', 'boolean'],
            ]);
            foreach ($data as $k => $v) {
                $this->putSetting($schoolId, $k, is_bool($v) ? ($v ? '1' : '0') : (string) $v, gettype($v));
            }
            $this->audit($request, 'admin.settings', 'settings', null, $data);

            return $this->adminSettingsShow($request);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function gatewayShow(Request $request, string $channel)
    {
        try {
            $schoolId = $this->schoolId($request);
            $map = $this->settingsMap($schoolId);
            $prefix = match ($channel) {
                'email' => 'email_',
                'sms' => 'sms_',
                'whatsapp' => 'whatsapp_',
                default => null,
            };
            if (! $prefix) {
                return $this->error('Unknown channel', 404);
            }

            return $this->success([
                'channel' => $channel,
                'config' => [
                    'enabled' => (bool) ($map[$prefix.'enabled'] ?? false),
                    'provider' => $map[$prefix.'provider'] ?? '',
                    'sender_id' => $map[$prefix.'sender_id'] ?? '',
                    'from_address' => $map[$prefix.'from_address'] ?? '',
                    'api_key_set' => ! empty($map[$prefix.'api_key']),
                    'notes' => $map[$prefix.'notes'] ?? '',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function gatewayUpdate(Request $request, string $channel)
    {
        try {
            $schoolId = $this->schoolId($request);
            if (! in_array($channel, ['email', 'sms', 'whatsapp'], true)) {
                return $this->error('Unknown channel', 404);
            }
            $data = $request->validate([
                'enabled' => ['sometimes', 'boolean'],
                'provider' => ['nullable', 'string', 'max:80'],
                'sender_id' => ['nullable', 'string', 'max:40'],
                'from_address' => ['nullable', 'string', 'max:120'],
                'api_key' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
            ]);
            $prefix = $channel.'_';
            foreach ($data as $k => $v) {
                if ($v === null && $k !== 'api_key') {
                    continue;
                }
                if ($k === 'enabled') {
                    $this->putSetting($schoolId, $prefix.'enabled', $v ? '1' : '0', 'boolean');
                } else {
                    $this->putSetting($schoolId, $prefix.$k, (string) $v, 'string');
                }
            }
            $this->audit($request, 'admin.gateway.'.$channel, 'settings', null, array_keys($data));

            return $this->gatewayShow($request, $channel);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function templatesIndex(Request $request)
    {
        try {
            $items = NotificationTemplate::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('channel')
                ->orderBy('key')
                ->get();

            return $this->success(['templates' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function templatesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'channel' => ['required', 'in:email,sms,whatsapp,push'],
                'key' => ['required', 'string', 'max:80'],
                'subject' => ['nullable', 'string', 'max:190'],
                'body' => ['required', 'string'],
            ]);
            $item = NotificationTemplate::query()->updateOrCreate(
                [
                    'school_id' => $this->schoolId($request),
                    'channel' => $data['channel'],
                    'key' => $data['key'],
                ],
                [
                    'subject' => $data['subject'] ?? null,
                    'body' => $data['body'],
                ]
            );

            return $this->success(['template' => $item], 'Template saved', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function webhooksIndex(Request $request)
    {
        try {
            $items = Webhook::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('name')
                ->get();

            return $this->success(['webhooks' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function webhooksStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'url' => ['required', 'url', 'max:500'],
                'events' => ['nullable', 'array'],
                'is_active' => ['nullable', 'boolean'],
            ]);
            $item = Webhook::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success(['webhook' => $item], 'Webhook created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function webhooksUpdate(Request $request, int $id)
    {
        try {
            $item = Webhook::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'url' => ['sometimes', 'url', 'max:500'],
                'events' => ['nullable', 'array'],
                'is_active' => ['nullable', 'boolean'],
            ]);
            $item->fill($data)->save();

            return $this->success(['webhook' => $item->fresh()], 'Webhook updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function webhooksDestroy(Request $request, int $id)
    {
        try {
            $item = Webhook::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $item->delete();

            return $this->success(['deleted' => true], 'Webhook removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function backupStatus(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $map = $this->settingsMap($schoolId);

            return $this->success([
                'backup' => [
                    'enabled' => (bool) ($map['backup_enabled'] ?? true),
                    'last_backup_at' => $map['backup_last_at'] ?? null,
                    'schedule' => $map['backup_schedule'] ?? 'daily',
                    'retention_days' => (int) ($map['backup_retention_days'] ?? 30),
                    'note' => 'Backups are managed by the platform operations team. Request a restore via support.',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function backupRequest(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->putSetting($schoolId, 'backup_last_request_at', now()->toIso8601String(), 'string');
            $this->audit($request, 'admin.backup.request', 'backup', null, []);

            return $this->success([
                'requested' => true,
                'message' => 'Backup request logged. Platform ops will process it.',
            ], 'Backup requested');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function systemLogs(Request $request)
    {
        return $this->auditIndex($request);
    }

    public function apiKeysShow(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $map = $this->settingsMap($schoolId);
            $key = $map['api_public_key'] ?? null;

            return $this->success([
                'api_keys' => [
                    'public_key' => $key,
                    'has_secret' => ! empty($map['api_secret_key']),
                    'created_at' => $map['api_key_created_at'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function apiKeysRotate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $public = 'pk_'.Str::lower(Str::random(24));
            $secret = 'sk_'.Str::lower(Str::random(40));
            $this->putSetting($schoolId, 'api_public_key', $public, 'string');
            $this->putSetting($schoolId, 'api_secret_key', $secret, 'string');
            $this->putSetting($schoolId, 'api_key_created_at', now()->toIso8601String(), 'string');
            $this->audit($request, 'admin.api_keys.rotate', 'settings', null, []);

            return $this->success([
                'api_keys' => [
                    'public_key' => $public,
                    'secret_key' => $secret,
                    'created_at' => now()->toIso8601String(),
                ],
            ], 'API keys rotated — store the secret securely; it will not be shown again.');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    protected function transformUser(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role_id' => $u->role_id,
            'role' => $u->role ? [
                'id' => $u->role->id,
                'name' => $u->role->name,
                'label' => $u->role->label ?: $u->role->name,
                'scope' => $u->role->scope ?? 'school',
            ] : null,
            'created_at' => optional($u->created_at)?->toIso8601String(),
        ];
    }

    protected function settingsMap(int $schoolId): array
    {
        return SystemSetting::query()
            ->where('school_id', $schoolId)
            ->pluck('value', 'key')
            ->all();
    }

    protected function putSetting(int $schoolId, string $key, string $value, string $type = 'string'): void
    {
        SystemSetting::query()->updateOrCreate(
            ['school_id' => $schoolId, 'key' => $key],
            ['value' => $value, 'type' => $type]
        );
    }

    protected function audit(Request $request, string $action, ?string $entityType, ?int $entityId, mixed $changes): void
    {
        try {
            AuditLog::query()->create([
                'school_id' => $this->schoolId($request),
                'user_id' => $request->user()?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'changes' => is_array($changes) ? $changes : ['detail' => $changes],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 250),
            ]);
        } catch (Throwable) {
            // never break primary flow
        }
    }

    protected function ensureSchoolRolesAndPermissions(): void
    {
        $perms = [
            ['name' => 'students.view', 'label' => 'View students', 'module' => 'students'],
            ['name' => 'students.manage', 'label' => 'Manage students', 'module' => 'students'],
            ['name' => 'finance.view', 'label' => 'View finance', 'module' => 'finance'],
            ['name' => 'finance.manage', 'label' => 'Manage finance', 'module' => 'finance'],
            ['name' => 'exams.view', 'label' => 'View examinations', 'module' => 'examinations'],
            ['name' => 'exams.manage', 'label' => 'Manage examinations', 'module' => 'examinations'],
            ['name' => 'attendance.manage', 'label' => 'Mark attendance', 'module' => 'attendance'],
            ['name' => 'staff.manage', 'label' => 'Manage staff', 'module' => 'staff'],
            ['name' => 'reports.view', 'label' => 'View reports', 'module' => 'reports'],
            ['name' => 'users.manage', 'label' => 'Manage users', 'module' => 'users'],
            ['name' => 'settings.manage', 'label' => 'Manage settings', 'module' => 'admin'],
            ['name' => 'compliance.export', 'label' => 'Compliance exports', 'module' => 'compliance'],
        ];
        foreach ($perms as $p) {
            Permission::query()->firstOrCreate(['name' => $p['name']], $p);
        }

        $roles = [
            ['name' => 'school_admin', 'label' => 'School Administrator', 'scope' => 'school', 'is_system' => true],
            ['name' => 'headteacher', 'label' => 'Head Teacher', 'scope' => 'school', 'is_system' => true],
            ['name' => 'teacher', 'label' => 'Teacher', 'scope' => 'school', 'is_system' => true],
            ['name' => 'accountant', 'label' => 'Accountant', 'scope' => 'school', 'is_system' => true],
            ['name' => 'clerk', 'label' => 'Clerk / Registrar', 'scope' => 'school', 'is_system' => true],
            ['name' => 'parent', 'label' => 'Parent / Guardian', 'scope' => 'family', 'is_system' => true],
            ['name' => 'student', 'label' => 'Student', 'scope' => 'family', 'is_system' => true],
        ];
        $allPermNames = array_column($perms, 'name');
        foreach ($roles as $r) {
            $role = Role::query()->firstOrCreate(
                ['name' => $r['name']],
                [
                    'label' => $r['label'],
                    'scope' => $r['scope'],
                    'is_system' => $r['is_system'],
                    'permissions' => match ($r['name']) {
                        'school_admin', 'headteacher' => $allPermNames,
                        'teacher' => ['students.view', 'exams.view', 'exams.manage', 'attendance.manage', 'reports.view'],
                        'accountant' => ['finance.view', 'finance.manage', 'reports.view', 'students.view'],
                        'clerk' => ['students.view', 'students.manage', 'attendance.manage', 'reports.view'],
                        default => ['students.view'],
                    },
                ]
            );
            if (Schema::hasColumn('roles', 'scope') && empty($role->scope)) {
                $role->scope = $r['scope'];
                $role->is_system = true;
                $role->save();
            }
        }
    }
}
