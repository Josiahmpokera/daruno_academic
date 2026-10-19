<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rejects requests that reference another school's records.
 *
 * Every well-known foreign key in the query string or body (at any depth,
 * e.g. `results.*.student_id`, `student_ids[]`) must point at a row of the
 * current school. This guards all tenant endpoints, including ones whose
 * validation only checks the ID is an integer.
 */
class EnforceTenantReferences
{
    /** Input key => tenant table (every table here has a school_id column). */
    protected const KEY_TABLES = [
        'student_id' => 'students',
        'student_ids' => 'students',
        'classroom_id' => 'classrooms',
        'from_classroom_id' => 'classrooms',
        'to_classroom_id' => 'classrooms',
        'academic_year_id' => 'academic_years',
        'from_academic_year_id' => 'academic_years',
        'to_academic_year_id' => 'academic_years',
        'term_id' => 'terms',
        'subject_id' => 'subjects',
        'subject_ids' => 'subjects',
        'subject_category_id' => 'subject_categories',
        'education_level_id' => 'education_levels',
        'stream_id' => 'streams',
        'enrollment_id' => 'enrollments',
        'staff_id' => 'staff',
        'teacher_id' => 'staff',
        'department_id' => 'departments',
        'staff_position_id' => 'staff_positions',
        'grading_system_id' => 'grading_systems',
        'invoice_id' => 'fee_invoices',
        'payment_id' => 'fee_payments',
        'fee_category_id' => 'fee_categories',
        'payment_method_id' => 'payment_methods',
        'coa_id' => 'chart_of_accounts',
        'bank_account_id' => 'bank_accounts',
        'vendor_id' => 'vendors',
        'stock_item_id' => 'stock_items',
        'asset_id' => 'assets',
        'book_id' => 'library_books',
        'vehicle_id' => 'vehicles',
        'driver_id' => 'drivers',
        'route_id' => 'routes',
        'hostel_id' => 'hostels',
        'room_id' => 'hostel_rooms',
        'bed_id' => 'hostel_beds',
        'course_id' => 'courses',
        'assignment_id' => 'assignments',
        'card_ids' => 'student_id_cards',
        'guardian_id' => 'student_guardians',
        'classroom_ids' => 'classrooms',
    ];

    /** Keys whose table depends on the module (path pattern => key => table). */
    protected const PATH_KEY_TABLES = [
        'api/v1/library/*' => ['category_id' => 'library_categories'],
        'api/v1/inventory/*' => ['category_id' => 'asset_categories'],
    ];

    public function handle(Request $request, Closure $next)
    {
        $school = $request->attributes->get('school');
        if (! $school instanceof School) {
            return $next($request);
        }

        $map = self::KEY_TABLES;
        foreach (self::PATH_KEY_TABLES as $pattern => $extra) {
            if ($request->is($pattern)) {
                $map = array_merge($map, $extra);
            }
        }

        $refs = [];
        $this->collect($request->all(), $map, $refs);

        foreach ($refs as $table => $byKey) {
            $ids = array_values(array_unique(array_merge(...array_values($byKey))));
            $owned = DB::table($table)
                ->where('school_id', $school->id)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($byKey as $key => $keyIds) {
                if (array_diff($keyIds, $owned) !== []) {
                    return response()->json([
                        'success' => false,
                        'error' => "The selected {$key} does not exist in this school.",
                        'data' => (object) [],
                    ], 422);
                }
            }
        }

        return $next($request);
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<string, array<string, list<int>>>  $refs  table => key => ids
     */
    protected function collect(mixed $input, array $map, array &$refs): void
    {
        if (! is_array($input)) {
            return;
        }

        foreach ($input as $key => $value) {
            if (is_string($key) && isset($map[$key])) {
                $table = $map[$key];
                foreach (is_array($value) ? $value : [$value] as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        if ((int) $id > 0) {
                            $refs[$table][$key][] = (int) $id;
                        }
                    }
                }

                continue;
            }

            if (is_array($value)) {
                $this->collect($value, $map, $refs);
            }
        }
    }
}
