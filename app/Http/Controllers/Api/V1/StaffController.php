<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffContract;
use App\Models\StaffDocument;
use App\Models\StaffLeaveRequest;
use App\Models\StaffPosition;
use App\Models\User;
use Carbon\Carbon;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Industrial staff HR: directory, onboarding, contracts, attendance, leave, documents, payroll.
 */
class StaffController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Overview ──────────────────────────────────────────────────

    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $today = now()->toDateString();

            $byStatus = Staff::query()
                ->where('school_id', $schoolId)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return $this->success([
                'summary' => [
                    'total' => Staff::query()->where('school_id', $schoolId)->count(),
                    'active' => (int) ($byStatus['active'] ?? 0),
                    'on_leave' => (int) ($byStatus['on_leave'] ?? 0),
                    'inactive' => (int) ($byStatus['inactive'] ?? 0),
                    'terminated' => (int) ($byStatus['terminated'] ?? 0),
                    'onboarding' => Staff::query()
                        ->where('school_id', $schoolId)
                        ->whereIn('onboarding_status', ['draft', 'in_progress'])
                        ->count(),
                    'departments' => Department::query()->where('school_id', $schoolId)->where('is_active', true)->count(),
                    'positions' => StaffPosition::query()->where('school_id', $schoolId)->where('is_active', true)->count(),
                    'active_contracts' => StaffContract::query()->where('school_id', $schoolId)->where('status', 'active')->count(),
                    'expiring_contracts' => StaffContract::query()
                        ->where('school_id', $schoolId)
                        ->where('status', 'active')
                        ->whereNotNull('end_date')
                        ->whereBetween('end_date', [$today, now()->addDays(60)->toDateString()])
                        ->count(),
                    'pending_leave' => StaffLeaveRequest::query()->where('school_id', $schoolId)->where('status', 'pending')->count(),
                    'present_today' => StaffAttendanceRecord::query()
                        ->where('school_id', $schoolId)
                        ->whereDate('date', $today)
                        ->whereIn('status', ['present', 'late', 'half_day'])
                        ->count(),
                    'documents' => StaffDocument::query()->where('school_id', $schoolId)->count(),
                    'with_portal_user' => Staff::query()->where('school_id', $schoolId)->whereNotNull('user_id')->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Staff directory ───────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString();
            $departmentId = $request->integer('department_id') ?: null;
            $positionId = $request->integer('staff_position_id') ?: null;
            $onboarding = $request->string('onboarding_status')->toString();

            $query = Staff::query()
                ->where('school_id', $schoolId)
                ->with([
                    'department:id,name,code',
                    'staffPosition:id,name,code',
                    'user:id,name,email,role_id',
                ])
                ->withCount(['contracts', 'documents', 'leaveRequests'])
                ->orderBy('last_name')
                ->orderBy('first_name');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('middle_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('employee_code', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('position', 'like', "%{$q}%")
                        ->orWhere('national_id', 'like', "%{$q}%");
                });
            }
            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }
            if ($positionId) {
                $query->where('staff_position_id', $positionId);
            }
            if ($onboarding !== '') {
                $query->where('onboarding_status', $onboarding);
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 50))));
            $items = collect($page->items())->map(fn (Staff $s) => $this->transformStaff($s));

            return $this->success([
                'staff' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $this->validateStaffPayload($request, false);
            $this->assertBelongsToSchool($schoolId, $data['department_id'] ?? null, $data['staff_position_id'] ?? null);

            $staff = $this->createStaffRecord($schoolId, $data);

            return $this->success([
                'staff' => $this->transformStaff($staff),
            ], 'Staff created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Full industrial onboarding: profile + joining date + contract + optional portal login.
     */
    public function onboard(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:120'],
                'middle_name' => ['nullable', 'string', 'max:120'],
                'last_name' => ['required', 'string', 'max:120'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'employee_code' => ['nullable', 'string', 'max:50'],
                'national_id' => ['nullable', 'string', 'max:50'],
                'date_of_birth' => ['nullable', 'date'],
                'gender' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:255'],
                'emergency_contact_name' => ['nullable', 'string', 'max:160'],
                'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
                'department_id' => ['nullable', 'integer', $this->tenantExists($request, 'departments')],
                'staff_position_id' => ['nullable', 'integer', $this->tenantExists($request, 'staff_positions')],
                'employment_type' => ['nullable', 'string', 'max:50'],
                'hire_date' => ['required', 'date'],
                'contract_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
                'probation_end_date' => ['nullable', 'date'],
                'basic_salary' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'max:10'],
                'contract_title' => ['nullable', 'string', 'max:160'],
                'contract_terms' => ['nullable', 'string'],
                'bank_name' => ['nullable', 'string', 'max:120'],
                'bank_account' => ['nullable', 'string', 'max:80'],
                'bank_branch' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string'],
                'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
                'create_portal_user' => ['nullable', 'boolean'],
                'role_id' => ['nullable', 'integer', 'exists:roles,id'],
                'password' => ['nullable', 'string', 'min:8'],
            ]);

            $this->assertBelongsToSchool($schoolId, $data['department_id'] ?? null, $data['staff_position_id'] ?? null);

            $result = DB::transaction(function () use ($schoolId, $data, $request) {
                $staffData = $data;
                $staffData['hire_date'] = $data['hire_date'];
                $staffData['onboarding_status'] = 'completed';
                $staffData['status'] = $data['status'] ?? 'active';
                $staff = $this->createStaffRecord($schoolId, $staffData);

                $contract = StaffContract::query()->create([
                    'school_id' => $schoolId,
                    'staff_id' => $staff->id,
                    'contract_number' => $this->nextContractNumber($schoolId),
                    'title' => $data['contract_title'] ?? (($data['employment_type'] ?? 'Full-time').' employment'),
                    'employment_type' => $data['employment_type'] ?? 'Full-time',
                    'start_date' => $data['hire_date'],
                    'end_date' => $data['contract_end_date'] ?? null,
                    'probation_end_date' => $data['probation_end_date'] ?? null,
                    'basic_salary' => $data['basic_salary'] ?? null,
                    'currency' => $data['currency'] ?? 'TZS',
                    'status' => 'active',
                    'terms' => $data['contract_terms'] ?? null,
                    'signed_at' => now(),
                    'created_by' => $request->user()?->id,
                ]);

                $temporaryPassword = null;
                $user = null;
                if (! empty($data['create_portal_user'])) {
                    if (! TenantAccess::can($request->user(), 'users', TenantAccess::WRITE)) {
                        throw new \InvalidArgumentException('Only school administrators can create portal logins.');
                    }
                    $email = $data['email'] ?? null;
                    if (! $email) {
                        throw new \InvalidArgumentException('Email is required to create a portal login.');
                    }
                    if (User::query()->where('email', $email)->exists()) {
                        throw new \InvalidArgumentException('A user with this email already exists.');
                    }

                    $roleId = $data['role_id'] ?? Role::query()->where('name', 'clerk')->value('id');
                    if (! $roleId) {
                        $roleId = Role::query()->where('name', 'school_admin')->value('id');
                    }
                    $role = Role::query()->findOrFail($roleId);
                    if (($role->name ?? '') === 'super_admin') {
                        throw new \InvalidArgumentException('Cannot assign platform super admin to school staff.');
                    }

                    $temporaryPassword = $data['password'] ?? Str::password(12);
                    $user = User::query()->create([
                        'school_id' => $schoolId,
                        'role_id' => $role->id,
                        'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
                        'email' => $email,
                        'phone' => $data['phone'] ?? null,
                        'password' => Hash::make($temporaryPassword),
                    ]);
                    $staff->user_id = $user->id;
                    $staff->save();
                }

                $staff->load([
                    'department:id,name,code',
                    'staffPosition:id,name,code',
                    'user:id,name,email,role_id',
                    'contracts',
                ]);

                return [
                    'staff' => $this->transformStaff($staff, true),
                    'contract' => $this->transformContract($contract),
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role_id' => $user->role_id,
                    ] : null,
                    'temporary_password' => empty($data['password']) ? $temporaryPassword : null,
                ];
            });

            return $this->success($result, 'Staff onboarded successfully', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $staff = Staff::query()
                ->where('school_id', $schoolId)
                ->with([
                    'department',
                    'staffPosition',
                    'user:id,name,email,phone,role_id',
                    'contracts' => fn ($q) => $q->orderByDesc('start_date'),
                    'documents' => fn ($q) => $q->orderByDesc('id'),
                    'leaveRequests' => fn ($q) => $q->orderByDesc('id')->limit(20),
                ])
                ->withCount(['contracts', 'documents', 'leaveRequests'])
                ->findOrFail($id);

            return $this->success(['staff' => $this->transformStaff($staff, true)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $staff = Staff::query()->where('school_id', $schoolId)->findOrFail($id);

            $data = $this->validateStaffPayload($request, true);
            $this->assertBelongsToSchool(
                $schoolId,
                $data['department_id'] ?? $staff->department_id,
                $data['staff_position_id'] ?? $staff->staff_position_id,
            );

            if (array_key_exists('staff_position_id', $data)) {
                $data['position'] = $data['staff_position_id']
                    ? StaffPosition::query()->where('school_id', $schoolId)->where('id', $data['staff_position_id'])->value('name')
                    : null;
            }

            if (($data['status'] ?? null) === 'terminated' && ! $staff->terminated_at) {
                $data['terminated_at'] = $data['terminated_at'] ?? now()->toDateString();
            }

            $staff->fill($data);
            $staff->save();
            $staff->load(['department:id,name,code', 'staffPosition:id,name,code', 'user:id,name,email,role_id']);

            return $this->success([
                'staff' => $this->transformStaff($staff),
            ], 'Staff updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $staff = Staff::query()->where('school_id', $schoolId)->findOrFail($id);
            // Soft industrial approach: terminate instead of hard delete when history exists
            $hasHistory = $staff->contracts()->exists() || $staff->leaveRequests()->exists() || $staff->attendanceRecords()->exists();
            if ($hasHistory) {
                $staff->status = 'terminated';
                $staff->terminated_at = $staff->terminated_at ?? now()->toDateString();
                $staff->save();

                return $this->success([
                    'staff' => $this->transformStaff($staff->fresh()->load(['department', 'staffPosition'])),
                    'terminated' => true,
                ], 'Staff has HR history and was terminated instead of deleted');
            }
            $staff->delete();

            return $this->success(['deleted' => true], 'Staff removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Contracts ─────────────────────────────────────────────────

    public function contractsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $staffId = $request->integer('staff_id') ?: null;
            $status = $request->string('status')->toString();

            $items = StaffContract::query()
                ->where('school_id', $schoolId)
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->with(['staff:id,first_name,middle_name,last_name,employee_code,department_id,staff_position_id'])
                ->orderByDesc('start_date')
                ->limit(200)
                ->get()
                ->map(fn (StaffContract $c) => $this->transformContract($c));

            return $this->success(['contracts' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function contractsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'staff_id' => ['required', 'integer'],
                'contract_number' => ['nullable', 'string', 'max:60'],
                'title' => ['nullable', 'string', 'max:160'],
                'employment_type' => ['nullable', 'string', 'max:50'],
                'start_date' => ['required', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                'probation_end_date' => ['nullable', 'date'],
                'basic_salary' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'max:10'],
                'status' => ['nullable', 'in:draft,active,expired,terminated,renewed'],
                'terms' => ['nullable', 'string'],
                'signed_at' => ['nullable', 'date'],
            ]);

            $staff = Staff::query()->where('school_id', $schoolId)->findOrFail($data['staff_id']);

            // If activating, expire other active contracts for same staff
            $status = $data['status'] ?? 'active';
            if ($status === 'active') {
                StaffContract::query()
                    ->where('school_id', $schoolId)
                    ->where('staff_id', $staff->id)
                    ->where('status', 'active')
                    ->update(['status' => 'renewed']);
            }

            $contract = StaffContract::query()->create([
                'school_id' => $schoolId,
                'staff_id' => $staff->id,
                'contract_number' => $data['contract_number'] ?? $this->nextContractNumber($schoolId),
                'title' => $data['title'] ?? 'Employment contract',
                'employment_type' => $data['employment_type'] ?? $staff->employment_type ?? 'Full-time',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'probation_end_date' => $data['probation_end_date'] ?? null,
                'basic_salary' => $data['basic_salary'] ?? null,
                'currency' => $data['currency'] ?? 'TZS',
                'status' => $status,
                'terms' => $data['terms'] ?? null,
                'signed_at' => $data['signed_at'] ?? ($status === 'active' ? now() : null),
                'created_by' => $request->user()?->id,
            ]);

            // Sync denormalized employment fields on staff
            if ($status === 'active') {
                $staff->employment_type = $contract->employment_type;
                $staff->hire_date = $staff->hire_date ?: $contract->start_date;
                $staff->contract_end_date = $contract->end_date;
                $staff->save();
            }

            $contract->load('staff:id,first_name,middle_name,last_name,employee_code');

            return $this->success(['contract' => $this->transformContract($contract)], 'Contract created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function contractsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $contract = StaffContract::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'contract_number' => ['sometimes', 'nullable', 'string', 'max:60'],
                'title' => ['sometimes', 'nullable', 'string', 'max:160'],
                'employment_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'start_date' => ['sometimes', 'date'],
                'end_date' => ['nullable', 'date'],
                'probation_end_date' => ['nullable', 'date'],
                'basic_salary' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'max:10'],
                'status' => ['sometimes', 'in:draft,active,expired,terminated,renewed'],
                'terms' => ['nullable', 'string'],
                'signed_at' => ['nullable', 'date'],
            ]);
            $contract->fill($data)->save();

            if (($data['status'] ?? null) === 'active') {
                StaffContract::query()
                    ->where('school_id', $schoolId)
                    ->where('staff_id', $contract->staff_id)
                    ->where('id', '!=', $contract->id)
                    ->where('status', 'active')
                    ->update(['status' => 'renewed']);

                $staff = Staff::query()->find($contract->staff_id);
                if ($staff) {
                    $staff->employment_type = $contract->employment_type;
                    $staff->contract_end_date = $contract->end_date;
                    $staff->save();
                }
            }

            return $this->success([
                'contract' => $this->transformContract($contract->fresh()->load('staff:id,first_name,middle_name,last_name,employee_code')),
            ], 'Contract updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Documents ─────────────────────────────────────────────────

    public function documentsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $staffId = $request->integer('staff_id') ?: null;

            $items = StaffDocument::query()
                ->where('school_id', $schoolId)
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->with(['staff:id,first_name,middle_name,last_name,employee_code'])
                ->orderByDesc('id')
                ->limit(300)
                ->get()
                ->map(fn (StaffDocument $d) => $this->transformDocument($d));

            return $this->success(['documents' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function documentsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'staff_id' => ['required', 'integer'],
                'doc_type' => ['nullable', 'string', 'max:40'],
                'title' => ['required', 'string', 'max:160'],
                'file_path' => ['nullable', 'string', 'max:500'],
                'external_url' => ['nullable', 'string', 'max:500'],
                'issued_at' => ['nullable', 'date'],
                'expires_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);
            Staff::query()->where('school_id', $schoolId)->findOrFail($data['staff_id']);

            $doc = StaffDocument::query()->create([
                'school_id' => $schoolId,
                'staff_id' => $data['staff_id'],
                'doc_type' => $data['doc_type'] ?? 'other',
                'title' => $data['title'],
                'file_path' => $data['file_path'] ?? null,
                'external_url' => $data['external_url'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => $request->user()?->id,
            ]);

            return $this->success([
                'document' => $this->transformDocument($doc->load('staff:id,first_name,middle_name,last_name,employee_code')),
            ], 'Document recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function documentsDestroy(Request $request, int $id)
    {
        try {
            $doc = StaffDocument::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $doc->delete();

            return $this->success(['deleted' => true], 'Document deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Leave ─────────────────────────────────────────────────────

    public function leaveIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $status = $request->string('status')->toString();
            $staffId = $request->integer('staff_id') ?: null;

            $items = StaffLeaveRequest::query()
                ->where('school_id', $schoolId)
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->with(['staff:id,first_name,middle_name,last_name,employee_code', 'reviewer:id,name'])
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (StaffLeaveRequest $l) => $this->transformLeave($l));

            return $this->success(['leave_requests' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function leaveStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'staff_id' => ['required', 'integer'],
                'leave_type' => ['nullable', 'string', 'max:40'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'reason' => ['nullable', 'string'],
            ]);
            Staff::query()->where('school_id', $schoolId)->findOrFail($data['staff_id']);

            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);
            $days = $data['days'] ?? ($start->diffInDays($end) + 1);

            $leave = StaffLeaveRequest::query()->create([
                'school_id' => $schoolId,
                'staff_id' => $data['staff_id'],
                'leave_type' => $data['leave_type'] ?? 'annual',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $days,
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
            ]);

            return $this->success([
                'leave_request' => $this->transformLeave($leave->load('staff:id,first_name,middle_name,last_name,employee_code')),
            ], 'Leave request submitted', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function leaveApprove(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $leave = StaffLeaveRequest::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($leave->status !== 'pending') {
                return $this->error('Only pending leave can be approved.', 422);
            }
            $leave->status = 'approved';
            $leave->reviewed_by = $request->user()?->id;
            $leave->reviewed_at = now();
            $leave->rejection_reason = null;
            $leave->save();

            // Optionally mark staff on_leave if leave covers today
            $today = now()->toDateString();
            if ($leave->start_date->toDateString() <= $today && $leave->end_date->toDateString() >= $today) {
                Staff::query()->where('id', $leave->staff_id)->where('status', 'active')->update(['status' => 'on_leave']);
            }

            return $this->success([
                'leave_request' => $this->transformLeave($leave->fresh()->load(['staff:id,first_name,middle_name,last_name,employee_code', 'reviewer:id,name'])),
            ], 'Leave approved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function leaveReject(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $leave = StaffLeaveRequest::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($leave->status !== 'pending') {
                return $this->error('Only pending leave can be rejected.', 422);
            }
            $data = $request->validate([
                'rejection_reason' => ['nullable', 'string', 'max:255'],
            ]);
            $leave->status = 'rejected';
            $leave->reviewed_by = $request->user()?->id;
            $leave->reviewed_at = now();
            $leave->rejection_reason = $data['rejection_reason'] ?? 'Rejected';
            $leave->save();

            return $this->success([
                'leave_request' => $this->transformLeave($leave->fresh()->load(['staff:id,first_name,middle_name,last_name,employee_code', 'reviewer:id,name'])),
            ], 'Leave rejected');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Attendance ────────────────────────────────────────────────

    public function attendanceIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $date = $request->input('date') ?: now()->toDateString();
            $staffId = $request->integer('staff_id') ?: null;

            $items = StaffAttendanceRecord::query()
                ->where('school_id', $schoolId)
                ->whereDate('date', $date)
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->with(['staff:id,first_name,middle_name,last_name,employee_code,department_id,status'])
                ->orderBy('staff_id')
                ->get()
                ->map(fn (StaffAttendanceRecord $r) => $this->transformAttendance($r));

            $activeStaff = Staff::query()
                ->where('school_id', $schoolId)
                ->whereIn('status', ['active', 'on_leave'])
                ->count();

            $summary = [
                'date' => $date,
                'active_staff' => $activeStaff,
                'marked' => $items->count(),
                'present' => $items->whereIn('status', ['present', 'late', 'half_day'])->count(),
                'absent' => $items->where('status', 'absent')->count(),
                'on_leave' => $items->where('status', 'on_leave')->count(),
            ];

            return $this->success(['records' => $items, 'summary' => $summary]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function attendanceMark(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'date' => ['required', 'date'],
                'records' => ['required', 'array', 'min:1', 'max:500'],
                'records.*.staff_id' => ['required', 'integer'],
                'records.*.status' => ['required', 'in:present,absent,late,half_day,on_leave,holiday'],
                'records.*.check_in' => ['nullable', 'string', 'max:10'],
                'records.*.check_out' => ['nullable', 'string', 'max:10'],
                'records.*.notes' => ['nullable', 'string', 'max:255'],
            ]);

            $saved = DB::transaction(function () use ($schoolId, $data, $request) {
                $out = [];
                foreach ($data['records'] as $row) {
                    $exists = Staff::query()->where('school_id', $schoolId)->where('id', $row['staff_id'])->exists();
                    if (! $exists) {
                        continue;
                    }
                    $rec = StaffAttendanceRecord::query()->updateOrCreate(
                        [
                            'school_id' => $schoolId,
                            'staff_id' => $row['staff_id'],
                            'date' => $data['date'],
                        ],
                        [
                            'status' => $row['status'],
                            'check_in' => $row['check_in'] ?? null,
                            'check_out' => $row['check_out'] ?? null,
                            'notes' => $row['notes'] ?? null,
                            'marked_by' => $request->user()?->id,
                        ]
                    );
                    $out[] = $rec;
                }

                return $out;
            });

            return $this->success([
                'saved' => count($saved),
                'date' => $data['date'],
            ], 'Attendance saved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function attendanceRoster(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $date = $request->input('date') ?: now()->toDateString();

            $staff = Staff::query()
                ->where('school_id', $schoolId)
                ->whereIn('status', ['active', 'on_leave'])
                ->with(['department:id,name', 'staffPosition:id,name'])
                ->orderBy('last_name')
                ->get();

            $marks = StaffAttendanceRecord::query()
                ->where('school_id', $schoolId)
                ->whereDate('date', $date)
                ->get()
                ->keyBy('staff_id');

            $rows = $staff->map(function (Staff $s) use ($marks, $date) {
                $m = $marks->get($s->id);

                return [
                    'staff_id' => $s->id,
                    'full_name' => $s->full_name,
                    'employee_code' => $s->employee_code,
                    'department' => $s->department?->name,
                    'position' => $s->staffPosition?->name ?? $s->position,
                    'staff_status' => $s->status,
                    'date' => $date,
                    'attendance_status' => $m?->status,
                    'check_in' => $m?->check_in,
                    'check_out' => $m?->check_out,
                    'notes' => $m?->notes,
                    'marked' => (bool) $m,
                ];
            })->values();

            return $this->success(['roster' => $rows, 'date' => $date]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Payroll integration summary ───────────────────────────────

    public function payrollSummary(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);

            $staff = Staff::query()
                ->where('school_id', $schoolId)
                ->whereIn('status', ['active', 'on_leave'])
                ->with([
                    'department:id,name',
                    'staffPosition:id,name',
                    'contracts' => fn ($q) => $q->where('status', 'active')->orderByDesc('start_date'),
                ])
                ->orderBy('last_name')
                ->get();

            $rows = $staff->map(function (Staff $s) {
                $contract = $s->contracts->first();

                return [
                    'staff_id' => $s->id,
                    'employee_code' => $s->employee_code,
                    'full_name' => $s->full_name,
                    'department' => $s->department?->name,
                    'position' => $s->staffPosition?->name ?? $s->position,
                    'employment_type' => $contract?->employment_type ?? $s->employment_type,
                    'hire_date' => optional($s->hire_date)?->toDateString(),
                    'contract_end_date' => optional($contract?->end_date ?? $s->contract_end_date)?->toDateString(),
                    'basic_salary' => $contract?->basic_salary !== null ? (float) $contract->basic_salary : null,
                    'currency' => $contract?->currency ?? 'TZS',
                    'bank_name' => $s->bank_name,
                    'bank_account' => $s->bank_account,
                    'bank_branch' => $s->bank_branch,
                    'status' => $s->status,
                    'has_active_contract' => (bool) $contract,
                    'payroll_ready' => (bool) ($contract && $s->bank_account),
                ];
            })->values();

            return $this->success([
                'staff' => $rows,
                'summary' => [
                    'active_staff' => $rows->count(),
                    'with_salary' => $rows->whereNotNull('basic_salary')->count(),
                    'payroll_ready' => $rows->where('payroll_ready', true)->count(),
                    'missing_bank' => $rows->filter(fn ($r) => empty($r['bank_account']))->count(),
                    'missing_contract' => $rows->where('has_active_contract', false)->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Departments ───────────────────────────────────────────────

    public function departmentsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $items = Department::query()
                ->where('school_id', $schoolId)
                ->withCount('staff')
                ->orderBy('name')
                ->get()
                ->map(fn (Department $d) => $this->transformDepartment($d));

            return $this->success(['departments' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function departmentsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:50'],
                'description' => ['nullable', 'string', 'max:1000'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $code = $data['code'] ?? Str::upper(Str::slug(Str::limit($data['name'], 12, ''), '_'));

            $dept = Department::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $code ?: null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success([
                'department' => $this->transformDepartment($dept->loadCount('staff')),
            ], 'Department created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function departmentsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $dept = Department::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['sometimes', 'nullable', 'string', 'max:50'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $dept->fill($data);
            $dept->save();

            return $this->success([
                'department' => $this->transformDepartment($dept->loadCount('staff')),
            ], 'Department updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function departmentsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $dept = Department::query()->where('school_id', $schoolId)->findOrFail($id);
            $count = Staff::query()->where('department_id', $dept->id)->count();
            if ($count > 0) {
                $dept->is_active = false;
                $dept->save();

                return $this->success([
                    'department' => $this->transformDepartment($dept->loadCount('staff')),
                    'deactivated' => true,
                ], 'Department has staff and was deactivated instead of deleted');
            }
            $dept->delete();

            return $this->success(['deleted' => true], 'Department deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Roles & positions ─────────────────────────────────────────

    public function positionsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $items = StaffPosition::query()
                ->where('school_id', $schoolId)
                ->withCount('staff')
                ->orderBy('name')
                ->get()
                ->map(fn (StaffPosition $p) => $this->transformPosition($p));

            return $this->success(['positions' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function positionsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:50'],
                'description' => ['nullable', 'string', 'max:1000'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $code = $data['code'] ?? Str::upper(Str::slug(Str::limit($data['name'], 12, ''), '_'));

            $pos = StaffPosition::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $code ?: null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success([
                'position' => $this->transformPosition($pos->loadCount('staff')),
            ], 'Position created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function positionsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $pos = StaffPosition::query()->where('school_id', $schoolId)->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['sometimes', 'nullable', 'string', 'max:50'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            $pos->fill($data);
            $pos->save();

            if (isset($data['name'])) {
                Staff::query()
                    ->where('school_id', $schoolId)
                    ->where('staff_position_id', $pos->id)
                    ->update(['position' => $pos->name]);
            }

            return $this->success([
                'position' => $this->transformPosition($pos->loadCount('staff')),
            ], 'Position updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function positionsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $pos = StaffPosition::query()->where('school_id', $schoolId)->findOrFail($id);
            $count = Staff::query()->where('staff_position_id', $pos->id)->count();
            if ($count > 0) {
                $pos->is_active = false;
                $pos->save();

                return $this->success([
                    'position' => $this->transformPosition($pos->loadCount('staff')),
                    'deactivated' => true,
                ], 'Position has staff and was deactivated instead of deleted');
            }
            $pos->delete();

            return $this->success(['deleted' => true], 'Position deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    protected function validateStaffPayload(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'first_name' => [$req, 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => [$req, 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', $this->tenantExists($request, 'departments')],
            'staff_position_id' => ['nullable', 'integer', $this->tenantExists($request, 'staff_positions')],
            'employment_type' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'terminated_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
            'onboarding_status' => ['nullable', 'in:draft,in_progress,completed'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:80'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    protected function createStaffRecord(int $schoolId, array $data): Staff
    {
        $code = $data['employee_code'] ?? $this->nextEmployeeCode($schoolId);
        $positionName = null;
        if (! empty($data['staff_position_id'])) {
            $positionName = StaffPosition::query()
                ->where('school_id', $schoolId)
                ->where('id', $data['staff_position_id'])
                ->value('name');
        }

        $staff = Staff::query()->create([
            'school_id' => $schoolId,
            'employee_code' => $code,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'staff_position_id' => $data['staff_position_id'] ?? null,
            'position' => $positionName,
            'employment_type' => $data['employment_type'] ?? 'Full-time',
            'hire_date' => $data['hire_date'] ?? now()->toDateString(),
            'contract_end_date' => $data['contract_end_date'] ?? null,
            'terminated_at' => $data['terminated_at'] ?? null,
            'status' => $data['status'] ?? 'active',
            'onboarding_status' => $data['onboarding_status'] ?? 'completed',
            'national_id' => $data['national_id'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'address' => $data['address'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'bank_branch' => $data['bank_branch'] ?? null,
            'notes' => $data['notes'] ?? null,
            'user_id' => null,
        ]);

        return $staff->load(['department:id,name,code', 'staffPosition:id,name,code']);
    }

    protected function transformStaff(Staff $s, bool $detailed = false): array
    {
        $base = [
            'id' => $s->id,
            'employee_code' => $s->employee_code,
            'first_name' => $s->first_name,
            'middle_name' => $s->middle_name,
            'last_name' => $s->last_name,
            'full_name' => $s->full_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'department_id' => $s->department_id,
            'department' => $s->department ? [
                'id' => $s->department->id,
                'name' => $s->department->name,
                'code' => $s->department->code,
            ] : null,
            'staff_position_id' => $s->staff_position_id,
            'staff_position' => $s->staffPosition ? [
                'id' => $s->staffPosition->id,
                'name' => $s->staffPosition->name,
                'code' => $s->staffPosition->code,
            ] : null,
            'position' => $s->position,
            'employment_type' => $s->employment_type,
            'hire_date' => optional($s->hire_date)?->toDateString(),
            'contract_end_date' => optional($s->contract_end_date)?->toDateString(),
            'terminated_at' => optional($s->terminated_at)?->toDateString(),
            'status' => $s->status,
            'onboarding_status' => $s->onboarding_status ?? 'completed',
            'national_id' => $s->national_id,
            'date_of_birth' => optional($s->date_of_birth)?->toDateString(),
            'gender' => $s->gender,
            'address' => $s->address,
            'emergency_contact_name' => $s->emergency_contact_name,
            'emergency_contact_phone' => $s->emergency_contact_phone,
            'bank_name' => $s->bank_name,
            'bank_account' => $s->bank_account,
            'bank_branch' => $s->bank_branch,
            'notes' => $s->notes,
            'user_id' => $s->user_id,
            'has_portal_user' => (bool) $s->user_id,
            'user' => $s->user ? [
                'id' => $s->user->id,
                'name' => $s->user->name,
                'email' => $s->user->email,
            ] : null,
            'contracts_count' => (int) ($s->contracts_count ?? 0),
            'documents_count' => (int) ($s->documents_count ?? 0),
            'leave_requests_count' => (int) ($s->leave_requests_count ?? 0),
            'created_at' => $s->created_at,
        ];

        if ($detailed) {
            $base['contracts'] = ($s->relationLoaded('contracts') ? $s->contracts : collect())
                ->map(fn (StaffContract $c) => $this->transformContract($c))->values();
            $base['documents'] = ($s->relationLoaded('documents') ? $s->documents : collect())
                ->map(fn (StaffDocument $d) => $this->transformDocument($d))->values();
            $base['leave_requests'] = ($s->relationLoaded('leaveRequests') ? $s->leaveRequests : collect())
                ->map(fn (StaffLeaveRequest $l) => $this->transformLeave($l))->values();
        }

        return $base;
    }

    protected function transformContract(StaffContract $c): array
    {
        return [
            'id' => $c->id,
            'staff_id' => $c->staff_id,
            'staff' => $c->relationLoaded('staff') && $c->staff ? [
                'id' => $c->staff->id,
                'full_name' => $c->staff->full_name,
                'employee_code' => $c->staff->employee_code,
            ] : null,
            'contract_number' => $c->contract_number,
            'title' => $c->title,
            'employment_type' => $c->employment_type,
            'start_date' => optional($c->start_date)?->toDateString(),
            'end_date' => optional($c->end_date)?->toDateString(),
            'probation_end_date' => optional($c->probation_end_date)?->toDateString(),
            'basic_salary' => $c->basic_salary !== null ? (float) $c->basic_salary : null,
            'currency' => $c->currency,
            'status' => $c->status,
            'terms' => $c->terms,
            'signed_at' => optional($c->signed_at)?->toIso8601String(),
            'created_at' => $c->created_at,
        ];
    }

    protected function transformDocument(StaffDocument $d): array
    {
        return [
            'id' => $d->id,
            'staff_id' => $d->staff_id,
            'staff' => $d->relationLoaded('staff') && $d->staff ? [
                'id' => $d->staff->id,
                'full_name' => $d->staff->full_name,
                'employee_code' => $d->staff->employee_code,
            ] : null,
            'doc_type' => $d->doc_type,
            'title' => $d->title,
            'file_path' => $d->file_path,
            'external_url' => $d->external_url,
            'issued_at' => optional($d->issued_at)?->toDateString(),
            'expires_at' => optional($d->expires_at)?->toDateString(),
            'notes' => $d->notes,
            'created_at' => $d->created_at,
        ];
    }

    protected function transformLeave(StaffLeaveRequest $l): array
    {
        return [
            'id' => $l->id,
            'staff_id' => $l->staff_id,
            'staff' => $l->relationLoaded('staff') && $l->staff ? [
                'id' => $l->staff->id,
                'full_name' => $l->staff->full_name,
                'employee_code' => $l->staff->employee_code,
            ] : null,
            'leave_type' => $l->leave_type,
            'start_date' => optional($l->start_date)?->toDateString(),
            'end_date' => optional($l->end_date)?->toDateString(),
            'days' => $l->days,
            'reason' => $l->reason,
            'status' => $l->status,
            'reviewed_by' => $l->reviewed_by,
            'reviewer' => $l->relationLoaded('reviewer') && $l->reviewer ? [
                'id' => $l->reviewer->id,
                'name' => $l->reviewer->name,
            ] : null,
            'reviewed_at' => optional($l->reviewed_at)?->toIso8601String(),
            'rejection_reason' => $l->rejection_reason,
            'created_at' => $l->created_at,
        ];
    }

    protected function transformAttendance(StaffAttendanceRecord $r): array
    {
        return [
            'id' => $r->id,
            'staff_id' => $r->staff_id,
            'staff' => $r->relationLoaded('staff') && $r->staff ? [
                'id' => $r->staff->id,
                'full_name' => $r->staff->full_name,
                'employee_code' => $r->staff->employee_code,
                'status' => $r->staff->status,
            ] : null,
            'date' => optional($r->date)?->toDateString(),
            'status' => $r->status,
            'check_in' => $r->check_in,
            'check_out' => $r->check_out,
            'notes' => $r->notes,
        ];
    }

    protected function transformDepartment(Department $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'description' => $d->description,
            'is_active' => (bool) $d->is_active,
            'staff_count' => (int) ($d->staff_count ?? 0),
            'created_at' => $d->created_at,
        ];
    }

    protected function transformPosition(StaffPosition $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'description' => $p->description,
            'is_active' => (bool) $p->is_active,
            'staff_count' => (int) ($p->staff_count ?? 0),
            'created_at' => $p->created_at,
        ];
    }

    protected function nextEmployeeCode(int $schoolId): string
    {
        $n = Staff::query()->where('school_id', $schoolId)->count() + 1;
        do {
            $code = 'STF-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $exists = Staff::query()->where('school_id', $schoolId)->where('employee_code', $code)->exists();
            $n++;
        } while ($exists);

        return $code;
    }

    protected function nextContractNumber(int $schoolId): string
    {
        $n = StaffContract::query()->where('school_id', $schoolId)->count() + 1;
        do {
            $code = 'CTR-'.now()->format('Y').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $exists = StaffContract::query()->where('school_id', $schoolId)->where('contract_number', $code)->exists();
            $n++;
        } while ($exists);

        return $code;
    }

    protected function assertBelongsToSchool(int $schoolId, ?int $departmentId, ?int $positionId): void
    {
        if ($departmentId) {
            $ok = Department::query()->where('school_id', $schoolId)->where('id', $departmentId)->exists();
            if (! $ok) {
                throw new \InvalidArgumentException('Department does not belong to this school.');
            }
        }
        if ($positionId) {
            $ok = StaffPosition::query()->where('school_id', $schoolId)->where('id', $positionId)->exists();
            if (! $ok) {
                throw new \InvalidArgumentException('Position does not belong to this school.');
            }
        }
    }
}
