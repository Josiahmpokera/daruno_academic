<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Staff;
use App\Models\StaffPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class StaffController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Staff directory ───────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString();
            $departmentId = $request->integer('department_id') ?: null;
            $positionId = $request->integer('staff_position_id') ?: null;

            $query = Staff::query()
                ->where('school_id', $schoolId)
                ->with(['department:id,name,code', 'staffPosition:id,name,code'])
                ->orderBy('last_name')
                ->orderBy('first_name');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('employee_code', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('position', 'like', "%{$q}%");
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
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:120'],
                'last_name' => ['required', 'string', 'max:120'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'employee_code' => ['nullable', 'string', 'max:50'],
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'staff_position_id' => ['nullable', 'integer', 'exists:staff_positions,id'],
                'employment_type' => ['nullable', 'string', 'max:50'],
                'hire_date' => ['nullable', 'date'],
                'contract_end_date' => ['nullable', 'date'],
                'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
                'national_id' => ['nullable', 'string', 'max:50'],
            ]);

            $this->assertBelongsToSchool($schoolId, $data['department_id'] ?? null, $data['staff_position_id'] ?? null);

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
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'staff_position_id' => $data['staff_position_id'] ?? null,
                'position' => $positionName,
                'employment_type' => $data['employment_type'] ?? 'Full-time',
                'hire_date' => $data['hire_date'] ?? now()->toDateString(),
                'contract_end_date' => $data['contract_end_date'] ?? null,
                'status' => $data['status'] ?? 'active',
                'national_id' => $data['national_id'] ?? null,
                'user_id' => null,
            ]);

            $staff->load(['department:id,name,code', 'staffPosition:id,name,code']);

            return $this->success([
                'staff' => $this->transformStaff($staff),
            ], 'Staff created', 201);
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
                ->with(['department', 'staffPosition'])
                ->findOrFail($id);

            return $this->success(['staff' => $this->transformStaff($staff)]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $staff = Staff::query()->where('school_id', $schoolId)->findOrFail($id);

            $data = $request->validate([
                'first_name' => ['sometimes', 'string', 'max:120'],
                'last_name' => ['sometimes', 'string', 'max:120'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'employee_code' => ['sometimes', 'nullable', 'string', 'max:50'],
                'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
                'staff_position_id' => ['sometimes', 'nullable', 'integer', 'exists:staff_positions,id'],
                'employment_type' => ['sometimes', 'nullable', 'string', 'max:50'],
                'hire_date' => ['sometimes', 'nullable', 'date'],
                'contract_end_date' => ['sometimes', 'nullable', 'date'],
                'status' => ['sometimes', 'in:active,inactive,on_leave,terminated'],
                'national_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            ]);

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

            $staff->fill($data);
            $staff->save();
            $staff->load(['department:id,name,code', 'staffPosition:id,name,code']);

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
            $staff->delete();

            return $this->success(['deleted' => true], 'Staff removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
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

            // Keep denormalized position label on staff in sync
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

    protected function transformStaff(Staff $s): array
    {
        return [
            'id' => $s->id,
            'employee_code' => $s->employee_code,
            'first_name' => $s->first_name,
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
            'status' => $s->status,
            'national_id' => $s->national_id,
            'created_at' => $s->created_at,
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
