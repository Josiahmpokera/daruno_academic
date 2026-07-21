<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelFee;
use App\Models\HostelRoom;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HostelController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $beds = HostelBed::query()->where('school_id', $schoolId)->count();
            $occupied = HostelBed::query()->where('school_id', $schoolId)->where('status', 'occupied')->count();
            $checkedIn = HostelAllocation::query()
                ->where('school_id', $schoolId)
                ->where('status', 'checked_in')
                ->count();

            return $this->success([
                'summary' => [
                    'hostels' => Hostel::query()->where('school_id', $schoolId)->count(),
                    'rooms' => HostelRoom::query()->where('school_id', $schoolId)->count(),
                    'beds' => $beds,
                    'beds_occupied' => $occupied,
                    'beds_available' => max(0, $beds - $occupied),
                    'checked_in' => $checkedIn,
                    'occupancy_rate' => $beds > 0 ? round(($occupied / $beds) * 100, 1) : 0,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function hostelsIndex(Request $request)
    {
        try {
            $items = Hostel::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount(['rooms', 'allocations'])
                ->orderBy('name')
                ->get();

            return $this->success(['hostels' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function hostelsStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'gender' => ['nullable', 'in:male,female,mixed'],
                'warden_name' => ['nullable', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40'],
                'capacity' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
            ]);
            $item = Hostel::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'gender' => $data['gender'] ?? 'mixed',
                'status' => $data['status'] ?? 'active',
                'capacity' => $data['capacity'] ?? 0,
            ]);

            return $this->success(['hostel' => $item], 'Hostel created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function hostelsUpdate(Request $request, int $id)
    {
        try {
            $item = Hostel::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'gender' => ['nullable', 'in:male,female,mixed'],
                'warden_name' => ['nullable', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40'],
                'capacity' => ['nullable', 'integer', 'min:0'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success(['hostel' => $item->fresh()], 'Hostel updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function roomsIndex(Request $request)
    {
        try {
            $query = HostelRoom::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['hostel:id,name', 'beds'])
                ->withCount('beds')
                ->orderBy('hostel_id')
                ->orderBy('name');

            if ($request->integer('hostel_id')) {
                $query->where('hostel_id', $request->integer('hostel_id'));
            }

            return $this->success(['rooms' => $query->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function roomsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'hostel_id' => ['required', 'integer'],
                'name' => ['required', 'string', 'max:80'],
                'floor' => ['nullable', 'string', 'max:40'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
                'create_beds' => ['nullable', 'boolean'],
            ]);
            Hostel::query()->where('school_id', $schoolId)->findOrFail($data['hostel_id']);
            $capacity = (int) ($data['capacity'] ?? 1);

            $room = DB::transaction(function () use ($schoolId, $data, $capacity) {
                $room = HostelRoom::query()->create([
                    'school_id' => $schoolId,
                    'hostel_id' => $data['hostel_id'],
                    'name' => $data['name'],
                    'floor' => $data['floor'] ?? null,
                    'capacity' => $capacity,
                    'status' => $data['status'] ?? 'active',
                    'notes' => $data['notes'] ?? null,
                ]);

                if ($data['create_beds'] ?? true) {
                    for ($i = 1; $i <= $capacity; $i++) {
                        HostelBed::query()->create([
                            'school_id' => $schoolId,
                            'room_id' => $room->id,
                            'label' => 'Bed '.$i,
                            'status' => 'available',
                        ]);
                    }
                }

                return $room->load(['hostel', 'beds']);
            });

            return $this->success(['room' => $room], 'Room created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function roomsUpdate(Request $request, int $id)
    {
        try {
            $item = HostelRoom::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:80'],
                'floor' => ['nullable', 'string', 'max:40'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success(['room' => $item->fresh()->load(['hostel', 'beds'])], 'Room updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function bedsIndex(Request $request)
    {
        try {
            $query = HostelBed::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['room.hostel:id,name'])
                ->orderBy('room_id')
                ->orderBy('label');

            if ($request->integer('room_id')) {
                $query->where('room_id', $request->integer('room_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }

            return $this->success(['beds' => $query->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function bedsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'room_id' => ['required', 'integer'],
                'label' => ['required', 'string', 'max:40'],
                'status' => ['nullable', 'in:available,occupied,maintenance'],
            ]);
            HostelRoom::query()->where('school_id', $schoolId)->findOrFail($data['room_id']);
            $item = HostelBed::query()->create([
                'school_id' => $schoolId,
                'room_id' => $data['room_id'],
                'label' => $data['label'],
                'status' => $data['status'] ?? 'available',
            ]);

            return $this->success(['bed' => $item->load('room')], 'Bed created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function allocationsIndex(Request $request)
    {
        try {
            $status = $request->string('status')->toString();
            $query = HostelAllocation::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'student:id,first_name,last_name,admission_number',
                    'hostel:id,name',
                    'room:id,name',
                    'bed:id,label',
                ])
                ->orderByDesc('id');

            if ($status !== '') {
                $query->where('status', $status);
            }

            return $this->success(['allocations' => $query->limit(200)->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function checkIn(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer'],
                'hostel_id' => ['required', 'integer'],
                'room_id' => ['nullable', 'integer'],
                'bed_id' => ['nullable', 'integer'],
                'check_in_on' => ['nullable', 'date'],
                'fee_amount' => ['nullable', 'numeric', 'min:0'],
                'notes' => ['nullable', 'string'],
            ]);

            $allocation = DB::transaction(function () use ($schoolId, $data) {
                Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);
                Hostel::query()->where('school_id', $schoolId)->findOrFail($data['hostel_id']);

                $existing = HostelAllocation::query()
                    ->where('school_id', $schoolId)
                    ->where('student_id', $data['student_id'])
                    ->where('status', 'checked_in')
                    ->exists();
                if ($existing) {
                    throw new \InvalidArgumentException('Student is already checked in');
                }

                $bedId = $data['bed_id'] ?? null;
                if ($bedId) {
                    $bed = HostelBed::query()
                        ->where('school_id', $schoolId)
                        ->lockForUpdate()
                        ->findOrFail($bedId);
                    if ($bed->status === 'occupied') {
                        throw new \InvalidArgumentException('Bed is already occupied');
                    }
                    $bed->status = 'occupied';
                    $bed->save();
                }

                return HostelAllocation::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $data['student_id'],
                    'hostel_id' => $data['hostel_id'],
                    'room_id' => $data['room_id'] ?? null,
                    'bed_id' => $bedId,
                    'check_in_on' => $data['check_in_on'] ?? now()->toDateString(),
                    'status' => 'checked_in',
                    'fee_amount' => $data['fee_amount'] ?? 0,
                    'notes' => $data['notes'] ?? null,
                ])->load(['student', 'hostel', 'room', 'bed']);
            });

            return $this->success(['allocation' => $allocation], 'Student checked in', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function checkOut(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);

            $allocation = DB::transaction(function () use ($schoolId, $id) {
                $allocation = HostelAllocation::query()
                    ->where('school_id', $schoolId)
                    ->where('status', 'checked_in')
                    ->lockForUpdate()
                    ->findOrFail($id);

                $allocation->status = 'checked_out';
                $allocation->check_out_on = now()->toDateString();
                $allocation->save();

                if ($allocation->bed_id) {
                    $bed = HostelBed::query()->lockForUpdate()->find($allocation->bed_id);
                    if ($bed) {
                        $bed->status = 'available';
                        $bed->save();
                    }
                }

                return $allocation->load(['student', 'hostel', 'room', 'bed']);
            });

            return $this->success(['allocation' => $allocation], 'Student checked out');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function feesIndex(Request $request)
    {
        try {
            $items = HostelFee::query()
                ->where('school_id', $this->schoolId($request))
                ->with('hostel:id,name')
                ->orderByDesc('id')
                ->get();

            return $this->success(['fees' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function feesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'title' => ['required', 'string', 'max:160'],
                'amount' => ['required', 'numeric', 'min:0'],
                'hostel_id' => ['nullable', 'integer'],
                'period' => ['nullable', 'in:term,month,year,once'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
            ]);
            $item = HostelFee::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'period' => $data['period'] ?? 'term',
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success(['fee' => $item->load('hostel')], 'Hostel fee created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function occupancyReport(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $hostels = Hostel::query()
                ->where('school_id', $schoolId)
                ->with(['rooms.beds'])
                ->get()
                ->map(function (Hostel $h) {
                    $beds = $h->rooms->flatMap->beds;
                    $total = $beds->count();
                    $occupied = $beds->where('status', 'occupied')->count();

                    return [
                        'id' => $h->id,
                        'name' => $h->name,
                        'gender' => $h->gender,
                        'rooms' => $h->rooms->count(),
                        'beds' => $total,
                        'occupied' => $occupied,
                        'available' => max(0, $total - $occupied),
                        'occupancy_rate' => $total > 0 ? round(($occupied / $total) * 100, 1) : 0,
                    ];
                });

            return $this->success([
                'hostels' => $hostels,
                'summary' => [
                    'beds' => $hostels->sum('beds'),
                    'occupied' => $hostels->sum('occupied'),
                    'available' => $hostels->sum('available'),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
