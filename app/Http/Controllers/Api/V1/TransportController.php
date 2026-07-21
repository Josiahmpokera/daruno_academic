<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\RouteStop;
use App\Models\Student;
use App\Models\StudentRoute;
use App\Models\TransportFee;
use App\Models\TransportRoute;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransportController extends Controller
{
    use ApiResponds, ResolvesSchool;

    public function vehiclesIndex(Request $request)
    {
        try {
            $items = Vehicle::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('plate_number')
                ->get();

            return $this->success(['vehicles' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function vehiclesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['nullable', 'string', 'max:120'],
                'plate_number' => ['required', 'string', 'max:40'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', 'in:active,inactive,maintenance'],
            ]);
            $item = Vehicle::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success(['vehicle' => $item], 'Vehicle created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function vehiclesUpdate(Request $request, int $id)
    {
        try {
            $item = Vehicle::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['nullable', 'string', 'max:120'],
                'plate_number' => ['sometimes', 'string', 'max:40'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', 'in:active,inactive,maintenance'],
            ]);
            $item->fill($data)->save();

            return $this->success(['vehicle' => $item->fresh()], 'Vehicle updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function driversIndex(Request $request)
    {
        try {
            $items = Driver::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('name')
                ->get();

            return $this->success(['drivers' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function driversStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40'],
                'license_number' => ['nullable', 'string', 'max:60'],
                'status' => ['nullable', 'in:active,inactive'],
            ]);
            $item = Driver::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success(['driver' => $item], 'Driver created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function driversUpdate(Request $request, int $id)
    {
        try {
            $item = Driver::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40'],
                'license_number' => ['nullable', 'string', 'max:60'],
                'status' => ['nullable', 'in:active,inactive'],
            ]);
            $item->fill($data)->save();

            return $this->success(['driver' => $item->fresh()], 'Driver updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function routesIndex(Request $request)
    {
        try {
            $items = TransportRoute::query()
                ->where('school_id', $this->schoolId($request))
                ->withCount(['stops', 'studentRoutes'])
                ->orderBy('name')
                ->get();

            return $this->success(['routes' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function routesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
            ]);
            $item = TransportRoute::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
            ]);

            return $this->success(['route' => $item], 'Route created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function routesUpdate(Request $request, int $id)
    {
        try {
            $item = TransportRoute::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
            ]);
            $item->fill($data)->save();

            return $this->success(['route' => $item->fresh()], 'Route updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function stopsIndex(Request $request)
    {
        try {
            $query = RouteStop::query()
                ->whereHas('route', fn ($r) => $r->where('school_id', $this->schoolId($request)))
                ->with('route:id,name,code')
                ->orderBy('route_id')
                ->orderBy('sequence');

            if ($request->integer('route_id')) {
                $query->where('route_id', $request->integer('route_id'));
            }

            return $this->success(['stops' => $query->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function stopsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'route_id' => ['required', 'integer'],
                'name' => ['required', 'string', 'max:120'],
                'sequence' => ['nullable', 'integer', 'min:0'],
                'latitude' => ['nullable', 'numeric'],
                'longitude' => ['nullable', 'numeric'],
                'pickup_time' => ['nullable', 'date_format:H:i'],
                'drop_time' => ['nullable', 'date_format:H:i'],
            ]);
            TransportRoute::query()->where('school_id', $schoolId)->findOrFail($data['route_id']);

            $item = RouteStop::query()->create([
                ...$data,
                'sequence' => $data['sequence'] ?? 0,
            ]);

            return $this->success(['stop' => $item->load('route')], 'Stop created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function stopsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $item = RouteStop::query()
                ->whereHas('route', fn ($r) => $r->where('school_id', $schoolId))
                ->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'sequence' => ['nullable', 'integer', 'min:0'],
                'latitude' => ['nullable', 'numeric'],
                'longitude' => ['nullable', 'numeric'],
                'pickup_time' => ['nullable', 'date_format:H:i'],
                'drop_time' => ['nullable', 'date_format:H:i'],
            ]);
            $item->fill($data)->save();

            return $this->success(['stop' => $item->fresh()->load('route')], 'Stop updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function allocationsIndex(Request $request)
    {
        try {
            $items = StudentRoute::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'student:id,first_name,last_name,admission_number',
                    'route:id,name,code',
                    'stop:id,name,sequence',
                ])
                ->orderByDesc('id')
                ->get();

            return $this->success(['allocations' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function allocationsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer'],
                'route_id' => ['required', 'integer'],
                'stop_id' => ['nullable', 'integer'],
                'direction' => ['nullable', 'in:to,from,both'],
            ]);
            Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);
            TransportRoute::query()->where('school_id', $schoolId)->findOrFail($data['route_id']);

            $item = StudentRoute::query()->updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'student_id' => $data['student_id'],
                    'route_id' => $data['route_id'],
                ],
                [
                    'stop_id' => $data['stop_id'] ?? null,
                    'direction' => $data['direction'] ?? 'both',
                ]
            );

            return $this->success([
                'allocation' => $item->load(['student', 'route', 'stop']),
            ], 'Student allocated', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function allocationsDestroy(Request $request, int $id)
    {
        try {
            $item = StudentRoute::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $item->delete();

            return $this->success(['deleted' => true], 'Allocation removed');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function maintenanceIndex(Request $request)
    {
        try {
            $items = VehicleMaintenance::query()
                ->where('school_id', $this->schoolId($request))
                ->with('vehicle:id,name,plate_number')
                ->orderByDesc('serviced_on')
                ->get();

            return $this->success(['maintenances' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function maintenanceStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'vehicle_id' => ['required', 'integer'],
                'serviced_on' => ['required', 'date'],
                'type' => ['nullable', 'in:service,repair,inspection'],
                'description' => ['nullable', 'string', 'max:255'],
                'cost' => ['nullable', 'numeric', 'min:0'],
                'vendor' => ['nullable', 'string', 'max:120'],
                'next_service_on' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);
            Vehicle::query()->where('school_id', $schoolId)->findOrFail($data['vehicle_id']);

            $item = VehicleMaintenance::query()->create([
                'school_id' => $schoolId,
                ...$data,
                'type' => $data['type'] ?? 'service',
                'cost' => $data['cost'] ?? 0,
                'status' => 'completed',
            ]);

            return $this->success(['maintenance' => $item->load('vehicle')], 'Maintenance recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function feesIndex(Request $request)
    {
        try {
            $items = TransportFee::query()
                ->where('school_id', $this->schoolId($request))
                ->with([
                    'route:id,name',
                    'student:id,first_name,last_name,admission_number',
                ])
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
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'title' => ['required', 'string', 'max:160'],
                'amount' => ['required', 'numeric', 'min:0'],
                'route_id' => ['nullable', 'integer'],
                'student_id' => ['nullable', 'integer'],
                'period' => ['nullable', 'in:term,month,year,once'],
                'effective_from' => ['nullable', 'date'],
                'effective_to' => ['nullable', 'date'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
            ]);
            $item = TransportFee::query()->create([
                'school_id' => $schoolId,
                ...$data,
                'period' => $data['period'] ?? 'term',
                'status' => $data['status'] ?? 'active',
            ]);

            return $this->success(['fee' => $item->load(['route', 'student'])], 'Transport fee created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function feesUpdate(Request $request, int $id)
    {
        try {
            $item = TransportFee::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:160'],
                'amount' => ['sometimes', 'numeric', 'min:0'],
                'route_id' => ['nullable', 'integer'],
                'student_id' => ['nullable', 'integer'],
                'period' => ['nullable', 'in:term,month,year,once'],
                'status' => ['nullable', 'in:active,inactive'],
                'notes' => ['nullable', 'string'],
            ]);
            $item->fill($data)->save();

            return $this->success(['fee' => $item->fresh()->load(['route', 'student'])], 'Fee updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function assignmentsIndex(Request $request)
    {
        try {
            $items = VehicleAssignment::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['vehicle:id,name,plate_number', 'route:id,name', 'driver:id,name,phone'])
                ->orderByDesc('id')
                ->get();

            return $this->success(['assignments' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);

            return $this->success([
                'summary' => [
                    'vehicles' => Vehicle::query()->where('school_id', $schoolId)->count(),
                    'drivers' => Driver::query()->where('school_id', $schoolId)->count(),
                    'routes' => TransportRoute::query()->where('school_id', $schoolId)->count(),
                    'students_allocated' => StudentRoute::query()->where('school_id', $schoolId)->count(),
                    'open_maintenances' => VehicleMaintenance::query()
                        ->where('school_id', $schoolId)
                        ->whereNotNull('next_service_on')
                        ->whereDate('next_service_on', '<=', now()->addDays(30)->toDateString())
                        ->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
