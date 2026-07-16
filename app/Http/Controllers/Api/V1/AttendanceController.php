<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AttendanceController extends Controller
{
    use ApiResponds, ResolvesSchool;

    /**
     * Roster for a class on a date (with existing marks).
     */
    public function roster(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'classroom_id' => ['required', 'integer'],
                'date' => ['required', 'date'],
                'session' => ['nullable', 'in:morning,afternoon,full'],
                'academic_year_id' => ['nullable', 'integer'],
            ]);

            $session = $data['session'] ?? 'morning';
            $date = $data['date'];

            $enrollments = Enrollment::query()
                ->where('school_id', $schoolId)
                ->where('classroom_id', $data['classroom_id'])
                ->where('status', 'active')
                ->when(! empty($data['academic_year_id']), fn ($q) => $q->where('academic_year_id', $data['academic_year_id']))
                ->with('student')
                ->get();

            $records = AttendanceRecord::query()
                ->where('school_id', $schoolId)
                ->where('date', $date)
                ->where('type', 'student')
                ->where('source', $session)
                ->whereIn('student_id', $enrollments->pluck('student_id'))
                ->get()
                ->keyBy('student_id');

            $roster = $enrollments->map(function (Enrollment $e) use ($records) {
                $student = $e->student;
                $mark = $records->get($student?->id);

                return [
                    'student_id' => $student?->id,
                    'admission_number' => $student?->admission_number,
                    'full_name' => $student?->full_name,
                    'status' => $mark?->status,
                    'recorded_at' => $mark?->recorded_at,
                    'record_id' => $mark?->id,
                ];
            })->filter(fn ($r) => $r['student_id'])->values();

            return $this->success([
                'date' => $date,
                'session' => $session,
                'classroom_id' => (int) $data['classroom_id'],
                'roster' => $roster,
                'summary' => [
                    'total' => $roster->count(),
                    'present' => $roster->where('status', 'present')->count(),
                    'absent' => $roster->where('status', 'absent')->count(),
                    'late' => $roster->where('status', 'late')->count(),
                    'excused' => $roster->where('status', 'excused')->count(),
                    'unmarked' => $roster->whereNull('status')->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Bulk mark attendance for a class session.
     * marks: [{ student_id, status }]
     */
    public function mark(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'date' => ['required', 'date'],
                'session' => ['nullable', 'in:morning,afternoon,full'],
                'marks' => ['required', 'array', 'min:1'],
                'marks.*.student_id' => ['required', 'integer'],
                'marks.*.status' => ['required', 'in:present,absent,late,excused'],
            ]);

            $session = $data['session'] ?? 'morning';
            $date = $data['date'];

            $saved = DB::transaction(function () use ($schoolId, $data, $session, $date) {
                $out = [];
                foreach ($data['marks'] as $mark) {
                    Student::query()->where('school_id', $schoolId)->findOrFail($mark['student_id']);

                    $record = AttendanceRecord::query()->updateOrCreate(
                        [
                            'school_id' => $schoolId,
                            'date' => $date,
                            'student_id' => $mark['student_id'],
                            'type' => 'student',
                            'source' => $session,
                        ],
                        [
                            'status' => $mark['status'],
                            'recorded_at' => now()->format('H:i:s'),
                            'staff_id' => null,
                        ]
                    );
                    $out[] = $record;
                }

                return $out;
            });

            return $this->success([
                'date' => $date,
                'session' => $session,
                'saved' => count($saved),
                'records' => $saved,
            ], 'Attendance saved');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function index(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $query = AttendanceRecord::query()
                ->where('school_id', $schoolId)
                ->where('type', 'student')
                ->with('student')
                ->orderByDesc('date');

            if ($request->date('from')) {
                $query->whereDate('date', '>=', $request->date('from'));
            }
            if ($request->date('to')) {
                $query->whereDate('date', '<=', $request->date('to'));
            }
            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }

            return $this->success([
                'records' => $query->limit(200)->get(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
