<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeAdjustment;
use App\Models\FeeCategory;
use App\Models\FeeInvoice;
use App\Models\FeeInvoiceItem;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\FeeStructure;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FinanceController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ─── Categories ───────────────────────────────────────────────

    public function categoriesIndex(Request $request)
    {
        try {
            $items = FeeCategory::query()
                ->where('school_id', $this->schoolId($request))
                ->orderBy('name')
                ->get();

            return $this->success(['categories' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function categoriesStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:30'],
                'description' => ['nullable', 'string'],
                'is_compulsory' => ['nullable', 'boolean'],
                'is_recurring' => ['nullable', 'boolean'],
            ]);

            $item = FeeCategory::query()->create([
                'school_id' => $this->schoolId($request),
                ...$data,
            ]);

            return $this->success(['category' => $item], 'Category created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Fee structure setup completeness for the current academic year.
     * Used by the school dashboard banner so admins finish class × term fees.
     */
    public function setupStatus(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);

            $year = AcademicYear::query()
                ->where('school_id', $schoolId)
                ->where('is_current', true)
                ->first()
                ?? AcademicYear::query()
                    ->where('school_id', $schoolId)
                    ->orderByDesc('starts_at')
                    ->orderByDesc('id')
                    ->first();

            $categoriesCount = FeeCategory::query()->where('school_id', $schoolId)->count();
            $classrooms = Classroom::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->with('educationLevel:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'education_level_id']);

            if (! $year) {
                return $this->success([
                    'complete' => false,
                    'ready_for_fees' => false,
                    'academic_year' => null,
                    'summary' => [
                        'categories' => $categoriesCount,
                        'structures' => 0,
                        'classrooms' => $classrooms->count(),
                        'terms' => 0,
                        'covered_pairs' => 0,
                        'total_pairs' => 0,
                        'coverage_percent' => 0,
                        'missing_classrooms' => [],
                        'missing_terms' => [],
                        'missing_pairs' => [],
                        'missing_pairs_count' => 0,
                    ],
                    'blockers' => ['No academic year configured'],
                    'message' => 'Set up an academic year and fee structures so student fees can be tracked.',
                    'cta_path' => '/finance/structures',
                ]);
            }

            $terms = Term::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $year->id)
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get(['id', 'name', 'starts_at', 'ends_at']);

            $structures = FeeStructure::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->get(['id', 'term_id', 'classroom_id', 'fee_category_id', 'amount']);

            $structuresCount = $structures->count();

            // Build coverage matrix: each active classroom × each term
            // A structure covers a cell when:
            //   (classroom_id matches OR null = all classes) AND (term_id matches OR null = all terms)
            $missingPairs = [];
            $coveredPairs = 0;
            $classroomsMissingAny = [];
            $termsMissingAny = [];
            $termCovered = [];
            $classCovered = [];

            foreach ($terms as $t) {
                $termCovered[$t->id] = 0;
            }
            foreach ($classrooms as $c) {
                $classCovered[$c->id] = 0;
            }

            $totalPairs = 0;
            if ($classrooms->isEmpty() || $terms->isEmpty()) {
                $totalPairs = 0;
            } else {
                foreach ($classrooms as $classroom) {
                    $classHasAny = false;
                    foreach ($terms as $term) {
                        $totalPairs++;
                        $hit = $structures->first(function (FeeStructure $s) use ($classroom, $term) {
                            $classOk = $s->classroom_id === null || (int) $s->classroom_id === (int) $classroom->id;
                            $termOk = $s->term_id === null || (int) $s->term_id === (int) $term->id;

                            return $classOk && $termOk;
                        });
                        if ($hit) {
                            $coveredPairs++;
                            $classHasAny = true;
                            $termCovered[$term->id] = ($termCovered[$term->id] ?? 0) + 1;
                            $classCovered[$classroom->id] = ($classCovered[$classroom->id] ?? 0) + 1;
                        } else {
                            if (count($missingPairs) < 12) {
                                $missingPairs[] = [
                                    'classroom_id' => $classroom->id,
                                    'classroom' => $classroom->name,
                                    'education_level' => $classroom->educationLevel?->name,
                                    'term_id' => $term->id,
                                    'term' => $term->name,
                                ];
                            }
                        }
                    }
                    if (! $classHasAny) {
                        $classroomsMissingAny[] = [
                            'id' => $classroom->id,
                            'name' => $classroom->name,
                            'education_level' => $classroom->educationLevel?->name,
                        ];
                    }
                }
                foreach ($terms as $term) {
                    if (($termCovered[$term->id] ?? 0) === 0) {
                        $termsMissingAny[] = [
                            'id' => $term->id,
                            'name' => $term->name,
                        ];
                    }
                }
            }

            $missingPairsCount = max(0, $totalPairs - $coveredPairs);
            $coveragePercent = $totalPairs > 0
                ? (int) round(($coveredPairs / $totalPairs) * 100)
                : 0;

            $blockers = [];
            if ($categoriesCount === 0) {
                $blockers[] = 'No fee categories';
            }
            if ($classrooms->isEmpty()) {
                $blockers[] = 'No active classes';
            }
            if ($terms->isEmpty()) {
                $blockers[] = 'No terms for the current academic year';
            }
            if ($structuresCount === 0) {
                $blockers[] = 'No active fee structures for '.$year->name;
            } elseif ($missingPairsCount > 0) {
                $blockers[] = "{$missingPairsCount} class × term combination(s) still need a fee structure";
            }

            // Complete only when matrix is fully covered and prerequisites exist
            $complete = $categoriesCount > 0
                && $classrooms->isNotEmpty()
                && $terms->isNotEmpty()
                && $structuresCount > 0
                && $missingPairsCount === 0;

            $readyForFees = $complete;

            $message = $complete
                ? 'Fee structures cover all classes and terms for '.$year->name.'.'
                : (
                    $structuresCount === 0
                        ? 'Complete fee structure setup for all classes and terms so student fees can be tracked correctly.'
                        : "Fee setup is {$coveragePercent}% complete for {$year->name}. Finish remaining class and term fees to track student balances accurately."
                );

            return $this->success([
                'complete' => $complete,
                'ready_for_fees' => $readyForFees,
                'academic_year' => [
                    'id' => $year->id,
                    'name' => $year->name,
                    'is_current' => (bool) $year->is_current,
                ],
                'summary' => [
                    'categories' => $categoriesCount,
                    'structures' => $structuresCount,
                    'classrooms' => $classrooms->count(),
                    'terms' => $terms->count(),
                    'covered_pairs' => $coveredPairs,
                    'total_pairs' => $totalPairs,
                    'coverage_percent' => $coveragePercent,
                    'missing_classrooms' => $classroomsMissingAny,
                    'missing_terms' => $termsMissingAny,
                    'missing_pairs' => $missingPairs,
                    'missing_pairs_count' => $missingPairsCount,
                ],
                'blockers' => $blockers,
                'message' => $message,
                'cta_path' => '/finance/structures',
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── Structures ───────────────────────────────────────────────

    public function structuresIndex(Request $request)
    {
        try {
            $query = FeeStructure::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['category', 'academicYear', 'term', 'classroom'])
                ->orderByDesc('id');

            if ($request->integer('academic_year_id')) {
                $query->where('academic_year_id', $request->integer('academic_year_id'));
            }

            return $this->success(['structures' => $query->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function structuresStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'fee_category_id' => ['required', 'integer'],
                'amount' => ['required', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'size:3'],
                'due_date' => ['nullable', 'date'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $keys = [
                'academic_year_id' => (int) $data['academic_year_id'],
                'term_id' => array_key_exists('term_id', $data) ? $data['term_id'] : null,
                'classroom_id' => array_key_exists('classroom_id', $data) ? $data['classroom_id'] : null,
                'fee_category_id' => (int) $data['fee_category_id'],
            ];

            $existing = $this->findStructureByUniqueKeys($schoolId, $keys);
            if ($existing) {
                return $this->error(
                    $this->duplicateStructureMessage($existing),
                    422,
                    [
                        'code' => 'fee_structure_duplicate',
                        'existing_id' => $existing->id,
                        'structure' => $existing->load(['category', 'academicYear', 'term', 'classroom']),
                    ]
                );
            }

            try {
                $item = FeeStructure::query()->create([
                    'school_id' => $schoolId,
                    'currency' => $data['currency'] ?? 'TZS',
                    'is_active' => $data['is_active'] ?? true,
                    'academic_year_id' => $keys['academic_year_id'],
                    'term_id' => $keys['term_id'],
                    'classroom_id' => $keys['classroom_id'],
                    'fee_category_id' => $keys['fee_category_id'],
                    'amount' => $data['amount'],
                    'due_date' => $data['due_date'] ?? null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->isDuplicateKeyException($e)) {
                    $existing = $this->findStructureByUniqueKeys($schoolId, $keys);

                    return $this->error(
                        $existing
                            ? $this->duplicateStructureMessage($existing)
                            : 'A fee structure already exists for this year, term, class and category. Edit the existing line instead of creating a new one.',
                        422,
                        [
                            'code' => 'fee_structure_duplicate',
                            'existing_id' => $existing?->id,
                        ]
                    );
                }
                throw $e;
            }

            return $this->success([
                'structure' => $item->load(['category', 'academicYear', 'term', 'classroom']),
            ], 'Fee structure created', 201);
        } catch (Throwable $e) {
            return $this->error($this->friendlyFinanceError($e), 422);
        }
    }

    public function structuresUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $item = FeeStructure::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $data = $request->validate([
                'academic_year_id' => ['sometimes', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'fee_category_id' => ['sometimes', 'integer'],
                'amount' => ['sometimes', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'size:3'],
                'due_date' => ['nullable', 'date'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            // Allow clearing optional FKs when keys are present as null
            if ($request->exists('term_id') && $request->input('term_id') === null) {
                $data['term_id'] = null;
            }
            if ($request->exists('classroom_id') && $request->input('classroom_id') === null) {
                $data['classroom_id'] = null;
            }
            if ($request->exists('due_date') && $request->input('due_date') === null) {
                $data['due_date'] = null;
            }

            $keys = [
                'academic_year_id' => (int) ($data['academic_year_id'] ?? $item->academic_year_id),
                'term_id' => array_key_exists('term_id', $data) ? $data['term_id'] : $item->term_id,
                'classroom_id' => array_key_exists('classroom_id', $data) ? $data['classroom_id'] : $item->classroom_id,
                'fee_category_id' => (int) ($data['fee_category_id'] ?? $item->fee_category_id),
            ];

            $conflict = $this->findStructureByUniqueKeys($schoolId, $keys, $item->id);
            if ($conflict) {
                return $this->error(
                    $this->duplicateStructureMessage($conflict),
                    422,
                    [
                        'code' => 'fee_structure_duplicate',
                        'existing_id' => $conflict->id,
                        'structure' => $conflict->load(['category', 'academicYear', 'term', 'classroom']),
                    ]
                );
            }

            try {
                $item->fill($data)->save();
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->isDuplicateKeyException($e)) {
                    $conflict = $this->findStructureByUniqueKeys($schoolId, $keys, $item->id);

                    return $this->error(
                        $conflict
                            ? $this->duplicateStructureMessage($conflict)
                            : 'Another fee structure already uses this year, term, class and category combination.',
                        422,
                        [
                            'code' => 'fee_structure_duplicate',
                            'existing_id' => $conflict?->id,
                        ]
                    );
                }
                throw $e;
            }

            return $this->success([
                'structure' => $item->fresh()->load(['category', 'academicYear', 'term', 'classroom']),
            ], 'Fee structure updated');
        } catch (Throwable $e) {
            return $this->error($this->friendlyFinanceError($e), 422);
        }
    }

    /**
     * Match fee_structures unique key (school, year, term, classroom, category).
     * NULL term/class are matched with whereNull so duplicates are detected correctly.
     */
    protected function findStructureByUniqueKeys(int $schoolId, array $keys, ?int $exceptId = null): ?FeeStructure
    {
        $q = FeeStructure::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $keys['academic_year_id'])
            ->where('fee_category_id', $keys['fee_category_id']);

        if ($keys['term_id'] === null || $keys['term_id'] === '') {
            $q->whereNull('term_id');
        } else {
            $q->where('term_id', (int) $keys['term_id']);
        }

        if ($keys['classroom_id'] === null || $keys['classroom_id'] === '') {
            $q->whereNull('classroom_id');
        } else {
            $q->where('classroom_id', (int) $keys['classroom_id']);
        }

        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->first();
    }

    protected function duplicateStructureMessage(FeeStructure $existing): string
    {
        $existing->loadMissing(['category:id,name', 'academicYear:id,name', 'term:id,name', 'classroom:id,name']);

        $year = $existing->academicYear?->name ?? 'selected year';
        $term = $existing->term?->name ?? 'all terms';
        $class = $existing->classroom?->name ?? 'all classes';
        $category = $existing->category?->name ?? 'this category';
        $amount = number_format((float) $existing->amount, 0).' '.($existing->currency ?: 'TZS');

        return "A fee structure already exists for {$category} · {$year} · {$term} · {$class} ({$amount}). "
            .'Edit that line instead of creating a duplicate.';
    }

    protected function isDuplicateKeyException(\Illuminate\Database\QueryException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, '1062')
            || str_contains($msg, 'Duplicate entry')
            || str_contains($msg, 'fee_structures_unique')
            || ($e->errorInfo[1] ?? null) === 1062;
    }

    protected function friendlyFinanceError(Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException && $this->isDuplicateKeyException($e)) {
            return 'A fee structure already exists for this year, term, class and category. Edit the existing line instead.';
        }

        $msg = $e->getMessage();
        // Never leak raw SQLSTATE to the portal UI
        if (str_contains($msg, 'SQLSTATE') || str_contains($msg, 'Integrity constraint')) {
            return 'Could not save fee structure. Please check the values and try again.';
        }

        return $msg;
    }

    // ─── Invoices ─────────────────────────────────────────────────

    public function invoicesIndex(Request $request)
    {
        try {
            $query = FeeInvoice::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['student', 'items', 'academicYear', 'term'])
                ->orderByDesc('id');

            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }
            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }

            $perPage = min(100, max(1, $request->integer('per_page', 25)));
            $page = $query->paginate($perPage);

            return $this->success([
                'invoices' => $page->items(),
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

    /**
     * Generate invoices from active fee structures for a class or student list.
     */
    public function invoicesGenerate(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'academic_year_id' => ['required', 'integer'],
                'term_id' => ['nullable', 'integer'],
                'classroom_id' => ['nullable', 'integer'],
                'student_ids' => ['nullable', 'array'],
                'student_ids.*' => ['integer'],
                'due_date' => ['nullable', 'date'],
            ]);

            $structures = FeeStructure::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('is_active', true)
                ->when(! empty($data['term_id']), fn ($q) => $q->where(function ($q) use ($data) {
                    $q->whereNull('term_id')->orWhere('term_id', $data['term_id']);
                }))
                ->when(! empty($data['classroom_id']), fn ($q) => $q->where(function ($q) use ($data) {
                    $q->whereNull('classroom_id')->orWhere('classroom_id', $data['classroom_id']);
                }))
                ->with('category')
                ->get();

            if ($structures->isEmpty()) {
                return $this->error('No active fee structures found for the selection', 422);
            }

            $studentIds = $data['student_ids'] ?? [];
            if (empty($studentIds) && ! empty($data['classroom_id'])) {
                $studentIds = Enrollment::query()
                    ->where('school_id', $schoolId)
                    ->where('classroom_id', $data['classroom_id'])
                    ->where('academic_year_id', $data['academic_year_id'])
                    ->where('status', 'active')
                    ->pluck('student_id')
                    ->all();
            }

            if (empty($studentIds)) {
                return $this->error('No students selected for invoicing', 422);
            }

            $created = DB::transaction(function () use ($schoolId, $data, $structures, $studentIds) {
                $invoices = [];
                foreach ($studentIds as $studentId) {
                    Student::query()->where('school_id', $schoolId)->findOrFail($studentId);

                    $total = $structures->sum(fn ($s) => (float) $s->amount);
                    $ref = 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                    $invoice = FeeInvoice::query()->create([
                        'school_id' => $schoolId,
                        'student_id' => $studentId,
                        'academic_year_id' => $data['academic_year_id'],
                        'term_id' => $data['term_id'] ?? null,
                        'reference' => $ref,
                        'total_amount' => $total,
                        'balance_amount' => $total,
                        'status' => 'unpaid',
                        'issue_date' => now()->toDateString(),
                        'due_date' => $data['due_date'] ?? null,
                    ]);

                    foreach ($structures as $structure) {
                        FeeInvoiceItem::query()->create([
                            'invoice_id' => $invoice->id,
                            'fee_category_id' => $structure->fee_category_id,
                            'description' => $structure->category?->name ?? 'Fee',
                            'amount' => $structure->amount,
                        ]);
                    }

                    $invoices[] = $invoice->load(['student', 'items']);
                }

                return $invoices;
            });

            return $this->success([
                'invoices' => $created,
                'count' => count($created),
            ], 'Invoices generated', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Payments ─────────────────────────────────────────────────

    public function paymentsIndex(Request $request)
    {
        try {
            $query = FeePayment::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['student', 'invoice'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id');

            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }

            $limit = min(200, max(1, $request->integer('per_page', 100)));

            return $this->success(['payments' => $query->limit($limit)->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function paymentsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer'],
                'invoice_id' => ['nullable', 'integer'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'method' => ['nullable', 'string', 'max:50'],
                'payment_method_id' => ['nullable', 'integer'],
                'reference' => ['nullable', 'string', 'max:100'],
                'paid_at' => ['nullable', 'date'],
            ]);

            $payment = DB::transaction(function () use ($schoolId, $data) {
                Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);

                $methodLabel = $data['method'] ?? 'cash';
                if (! empty($data['payment_method_id'])) {
                    $pm = PaymentMethod::query()
                        ->where('school_id', $schoolId)
                        ->where('is_active', true)
                        ->findOrFail($data['payment_method_id']);
                    $methodLabel = $pm->name;
                }

                $invoice = null;
                if (! empty($data['invoice_id'])) {
                    $invoice = FeeInvoice::query()
                        ->where('school_id', $schoolId)
                        ->findOrFail($data['invoice_id']);

                    if ((int) $invoice->student_id !== (int) $data['student_id']) {
                        throw new \InvalidArgumentException('Invoice does not belong to this student');
                    }
                } else {
                    // Industrial auto-allocate: apply to oldest open invoice (FIFO)
                    $invoice = FeeInvoice::query()
                        ->where('school_id', $schoolId)
                        ->where('student_id', $data['student_id'])
                        ->where('balance_amount', '>', 0)
                        ->whereIn('status', ['unpaid', 'partial', 'overdue'])
                        ->orderBy('due_date')
                        ->orderBy('id')
                        ->first();
                }

                $ref = $data['reference'] ?? ('PAY-'.strtoupper(Str::random(8)));
                $payment = FeePayment::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $data['student_id'],
                    'invoice_id' => $invoice?->id,
                    'amount' => $data['amount'],
                    'refunded_amount' => 0,
                    'method' => $methodLabel,
                    'reference' => $ref,
                    'receipt_number' => 'RCP-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
                    'paid_at' => $data['paid_at'] ?? now(),
                    'status' => 'completed',
                ]);

                if ($invoice) {
                    $this->recalcInvoiceBalance($invoice);
                }

                // If payment exceeds one invoice balance, cascade remainder to next open invoices
                if ($invoice) {
                    $this->cascadePaymentAllocation(
                        $schoolId,
                        (int) $data['student_id'],
                        (float) $data['amount'],
                        (int) $invoice->id,
                        $methodLabel,
                        $ref
                    );
                }

                return $payment->load(['student', 'invoice']);
            });

            return $this->success(['payment' => $payment], 'Payment recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Receipts (completed payments) ────────────────────────────

    public function receiptsIndex(Request $request)
    {
        try {
            $query = FeePayment::query()
                ->where('school_id', $this->schoolId($request))
                ->where('status', 'completed')
                ->with(['student:id,first_name,middle_name,last_name,admission_number', 'invoice:id,reference,total_amount,balance_amount'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id');

            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }
            $q = $request->string('q')->toString();
            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('receipt_number', 'like', "%{$q}%")
                        ->orWhere('reference', 'like', "%{$q}%")
                        ->orWhereHas('student', function ($s) use ($q) {
                            $s->where('first_name', 'like', "%{$q}%")
                                ->orWhere('last_name', 'like', "%{$q}%")
                                ->orWhere('admission_number', 'like', "%{$q}%");
                        });
                });
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'receipts' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'summary' => [
                    'count' => FeePayment::query()
                        ->where('school_id', $this->schoolId($request))
                        ->where('status', 'completed')
                        ->count(),
                    'total_amount' => (float) FeePayment::query()
                        ->where('school_id', $this->schoolId($request))
                        ->where('status', 'completed')
                        ->sum('amount'),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── Refunds ──────────────────────────────────────────────────

    public function refundsIndex(Request $request)
    {
        try {
            $query = FeeRefund::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['student:id,first_name,last_name,admission_number', 'payment', 'invoice'])
                ->orderByDesc('id');

            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'refunds' => $page->items(),
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

    public function refundsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer'],
                'payment_id' => ['nullable', 'integer'],
                'invoice_id' => ['nullable', 'integer'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'method' => ['nullable', 'string', 'max:50'],
                'reason' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
            ]);

            $refund = DB::transaction(function () use ($schoolId, $data) {
                Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);

                $payment = null;
                if (! empty($data['payment_id'])) {
                    $payment = FeePayment::query()
                        ->where('school_id', $schoolId)
                        ->findOrFail($data['payment_id']);
                    $refundable = (float) $payment->amount - (float) $payment->refunded_amount;
                    if ($data['amount'] > $refundable + 0.001) {
                        throw new \InvalidArgumentException('Refund exceeds refundable payment amount');
                    }
                }

                $invoiceId = $data['invoice_id'] ?? $payment?->invoice_id;
                $invoice = $invoiceId
                    ? FeeInvoice::query()->where('school_id', $schoolId)->findOrFail($invoiceId)
                    : null;

                $refund = FeeRefund::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $data['student_id'],
                    'payment_id' => $payment?->id,
                    'invoice_id' => $invoice?->id,
                    'reference' => 'RFN-'.strtoupper(Str::random(8)),
                    'amount' => $data['amount'],
                    'method' => $data['method'] ?? $payment?->method ?? 'cash',
                    'reason' => $data['reason'] ?? null,
                    'status' => 'completed',
                    'refunded_at' => now(),
                    'notes' => $data['notes'] ?? null,
                ]);

                if ($payment) {
                    $payment->refunded_amount = (float) $payment->refunded_amount + (float) $data['amount'];
                    if ($payment->refunded_amount >= (float) $payment->amount - 0.001) {
                        $payment->status = 'refunded';
                    }
                    $payment->save();
                }

                if ($invoice) {
                    $this->recalcInvoiceBalance($invoice);
                }

                return $refund->load(['student', 'payment', 'invoice']);
            });

            return $this->success(['refund' => $refund], 'Refund recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Adjustments: discounts & fines ───────────────────────────

    public function adjustmentsIndex(Request $request)
    {
        try {
            $query = FeeAdjustment::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['student:id,first_name,last_name,admission_number', 'invoice:id,reference'])
                ->orderByDesc('id');

            $type = $request->string('type')->toString();
            if ($type !== '') {
                $query->where('type', $type);
            }
            if ($request->integer('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'adjustments' => $page->items(),
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

    public function adjustmentsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'student_id' => ['required', 'integer'],
                'invoice_id' => ['nullable', 'integer'],
                'type' => ['required', 'in:discount,fine'],
                'title' => ['required', 'string', 'max:255'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'effective_on' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);

            $adj = DB::transaction(function () use ($schoolId, $data) {
                Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);
                $invoice = null;
                if (! empty($data['invoice_id'])) {
                    $invoice = FeeInvoice::query()
                        ->where('school_id', $schoolId)
                        ->findOrFail($data['invoice_id']);
                }

                $adj = FeeAdjustment::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $data['student_id'],
                    'invoice_id' => $invoice?->id,
                    'type' => $data['type'],
                    'reference' => strtoupper($data['type'] === 'discount' ? 'DSC' : 'FIN').'-'.strtoupper(Str::random(7)),
                    'title' => $data['title'],
                    'amount' => $data['amount'],
                    'status' => 'posted',
                    'effective_on' => $data['effective_on'] ?? now()->toDateString(),
                    'notes' => $data['notes'] ?? null,
                ]);

                if ($invoice) {
                    if ($data['type'] === 'discount') {
                        $invoice->total_amount = max(0, (float) $invoice->total_amount - (float) $data['amount']);
                    } else {
                        $invoice->total_amount = (float) $invoice->total_amount + (float) $data['amount'];
                        FeeInvoiceItem::query()->create([
                            'invoice_id' => $invoice->id,
                            'fee_category_id' => null,
                            'description' => $data['title'].' (fine)',
                            'amount' => $data['amount'],
                        ]);
                    }
                    $invoice->save();
                    $this->recalcInvoiceBalance($invoice);
                }

                return $adj->load(['student', 'invoice']);
            });

            return $this->success(['adjustment' => $adj], 'Adjustment posted', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function adjustmentsVoid(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $adj = FeeAdjustment::query()->where('school_id', $schoolId)->findOrFail($id);
            if ($adj->status === 'void') {
                return $this->error('Already voided', 422);
            }

            DB::transaction(function () use ($adj) {
                if ($adj->invoice_id) {
                    $invoice = FeeInvoice::query()->find($adj->invoice_id);
                    if ($invoice) {
                        if ($adj->type === 'discount') {
                            $invoice->total_amount = (float) $invoice->total_amount + (float) $adj->amount;
                        } else {
                            $invoice->total_amount = max(0, (float) $invoice->total_amount - (float) $adj->amount);
                        }
                        $invoice->save();
                        $this->recalcInvoiceBalance($invoice);
                    }
                }
                $adj->status = 'void';
                $adj->save();
            });

            return $this->success(['adjustment' => $adj->fresh()], 'Adjustment voided');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Outstanding fees ─────────────────────────────────────────

    public function outstandingIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();

            $query = FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('balance_amount', '>', 0)
                ->whereIn('status', ['unpaid', 'partial', 'overdue'])
                ->with(['student:id,first_name,middle_name,last_name,admission_number,status'])
                ->orderByDesc('due_date')
                ->orderByDesc('id');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('reference', 'like', "%{$q}%")
                        ->orWhereHas('student', function ($s) use ($q) {
                            $s->where('first_name', 'like', "%{$q}%")
                                ->orWhere('last_name', 'like', "%{$q}%")
                                ->orWhere('admission_number', 'like', "%{$q}%");
                        });
                });
            }

            // Mark overdue
            FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('balance_amount', '>', 0)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->whereIn('status', ['unpaid', 'partial'])
                ->update(['status' => 'overdue']);

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            $totalOutstanding = (float) FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('balance_amount', '>', 0)
                ->sum('balance_amount');

            return $this->success([
                'invoices' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
                'summary' => [
                    'total_outstanding' => round($totalOutstanding, 2),
                    'invoice_count' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Finance & Fees dashboard: expected fees from structure × enrolled students,
     * collections, outstanding, and coverage for a selected (or current) academic year.
     */
    public function overview(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $requestedYearId = $request->integer('academic_year_id') ?: null;

            $year = $requestedYearId
                ? AcademicYear::query()
                    ->where('school_id', $schoolId)
                    ->find($requestedYearId)
                : null;

            if (! $year) {
                $year = AcademicYear::query()
                    ->where('school_id', $schoolId)
                    ->where('is_current', true)
                    ->first()
                    ?? AcademicYear::query()
                        ->where('school_id', $schoolId)
                        ->orderByDesc('starts_at')
                        ->orderByDesc('id')
                        ->first();
            }

            $yearId = $year?->id;

            $structures = $yearId
                ? FeeStructure::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $yearId)
                    ->where('is_active', true)
                    ->get(['id', 'term_id', 'classroom_id', 'fee_category_id', 'amount'])
                : collect();

            $enrollments = $yearId
                ? Enrollment::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $yearId)
                    ->whereIn('status', ['active', 'promoted', 'repeated'])
                    ->get(['id', 'student_id', 'classroom_id'])
                : collect();

            // One enrollment per student (latest)
            $enrollByStudent = $enrollments->groupBy('student_id')->map(fn ($g) => $g->sortByDesc('id')->first());
            $studentCount = $enrollByStudent->count();

            // Expected fee per student = sum of structures matching their classroom (any term or school-wide)
            $expectedTotal = 0.0;
            $studentsWithStructure = 0;
            $byClassExpected = [];

            foreach ($enrollByStudent as $studentId => $enr) {
                $classId = $enr->classroom_id ? (int) $enr->classroom_id : null;
                $studentExpected = $structures
                    ->filter(function (FeeStructure $s) use ($classId) {
                        return $s->classroom_id === null
                            || ($classId !== null && (int) $s->classroom_id === $classId);
                    })
                    ->sum(fn (FeeStructure $s) => (float) $s->amount);

                if ($studentExpected > 0.009) {
                    $studentsWithStructure++;
                }
                $expectedTotal += $studentExpected;

                $key = $classId ?? 0;
                if (! isset($byClassExpected[$key])) {
                    $byClassExpected[$key] = [
                        'classroom_id' => $classId,
                        'students' => 0,
                        'expected' => 0.0,
                    ];
                }
                $byClassExpected[$key]['students']++;
                $byClassExpected[$key]['expected'] += $studentExpected;
            }

            $studentIds = $enrollByStudent->keys()->map(fn ($id) => (int) $id)->all();

            // Collections: all completed payments for enrolled students (year ledger)
            // Prefer payments linked to year invoices + unallocated payments for those students
            $yearInvoiceIds = $yearId
                ? FeeInvoice::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $yearId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
                : [];
            $yearInvoiceSet = array_fill_keys($yearInvoiceIds, true);

            $payments = $studentIds === []
                ? collect()
                : FeePayment::query()
                    ->where('school_id', $schoolId)
                    ->whereIn('student_id', $studentIds)
                    ->where('status', 'completed')
                    ->get(['id', 'student_id', 'invoice_id', 'amount', 'refunded_amount']);

            $collections = 0.0;
            $payers = [];
            foreach ($payments as $p) {
                $include = false;
                if ($p->invoice_id === null || $p->invoice_id === '') {
                    $include = true;
                } elseif (isset($yearInvoiceSet[(int) $p->invoice_id])) {
                    $include = true;
                }
                if (! $include) {
                    continue;
                }
                $net = max(0, (float) $p->amount - (float) ($p->refunded_amount ?? 0));
                $collections += $net;
                $payers[(int) $p->student_id] = true;
            }

            // Invoice-based billed & outstanding for the year
            $invoicesYear = $yearId
                ? FeeInvoice::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $yearId)
                    ->get(['id', 'student_id', 'total_amount', 'balance_amount', 'status'])
                : collect();

            $billedInvoices = (float) $invoicesYear->sum('total_amount');
            $outstandingInvoices = (float) $invoicesYear->sum(fn ($i) => max(0, (float) $i->balance_amount));

            // Industrial outstanding for dashboard: expected − collected when structure is the plan;
            // fall back to invoice outstanding when expected is zero
            $outstanding = $expectedTotal > 0.009
                ? max(0, $expectedTotal - $collections)
                : $outstandingInvoices;

            $structureLines = $structures->count();
            $categories = FeeCategory::query()->where('school_id', $schoolId)->count();
            $classroomsWithStructure = $structures->pluck('classroom_id')->filter()->unique()->count();
            $collectionRate = $expectedTotal > 0.009
                ? round(min(100, ($collections / $expectedTotal) * 100), 1)
                : ($billedInvoices > 0.009 ? round(min(100, ($collections / $billedInvoices) * 100), 1) : 0.0);

            // Month-to-date collections (for secondary hint)
            $monthStart = now()->startOfMonth()->toDateString();
            $monthCollections = (float) FeePayment::query()
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereDate('paid_at', '>=', $monthStart)
                ->sum(DB::raw('amount - COALESCE(refunded_amount,0)'));

            $monthRefunds = (float) FeeRefund::query()
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereDate('refunded_at', '>=', $monthStart)
                ->sum('amount');

            return $this->success([
                'academic_year' => $year ? [
                    'id' => $year->id,
                    'name' => $year->name,
                    'is_current' => (bool) $year->is_current,
                ] : null,
                'summary' => [
                    'enrolled_students' => $studentCount,
                    'students_with_structure' => $studentsWithStructure,
                    'students_paid' => count($payers),
                    'structure_lines' => $structureLines,
                    'categories' => $categories,
                    'classrooms_with_structure' => $classroomsWithStructure,
                    // Core fee analytics (structure × students)
                    'expected_fees' => round($expectedTotal, 2),
                    'collections' => round($collections, 2),
                    'outstanding' => round($outstanding, 2),
                    'collection_rate' => $collectionRate,
                    // Invoice ledger (supplementary)
                    'billed_invoices' => round($billedInvoices, 2),
                    'outstanding_invoices' => round($outstandingInvoices, 2),
                    'invoice_count' => $invoicesYear->count(),
                    // Month-to-date
                    'month_collections' => round($monthCollections, 2),
                    'month_refunds' => round($monthRefunds, 2),
                    'month_net_collections' => round($monthCollections - $monthRefunds, 2),
                    // Aliases used by older UI
                    'billed' => round($expectedTotal > 0.009 ? $expectedTotal : $billedInvoices, 2),
                    'net_collections' => round($collections - $monthRefunds, 2),
                ],
                'by_class' => collect($byClassExpected)
                    ->map(function ($row) {
                        $name = null;
                        if ($row['classroom_id']) {
                            $name = Classroom::query()->where('id', $row['classroom_id'])->value('name');
                        }

                        return [
                            'classroom_id' => $row['classroom_id'],
                            'classroom' => $name ?? 'Unassigned',
                            'students' => $row['students'],
                            'expected' => round($row['expected'], 2),
                        ];
                    })
                    ->sortBy('classroom')
                    ->values(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── Financial reports (fees) ─────────────────────────────────

    public function financialReports(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $from = $request->date('from') ?: now()->startOfMonth()->toDateString();
            $to = $request->date('to') ?: now()->toDateString();

            $collections = (float) FeePayment::query()
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereDate('paid_at', '>=', $from)
                ->whereDate('paid_at', '<=', $to)
                ->sum(DB::raw('amount - COALESCE(refunded_amount,0)'));

            $refunds = (float) FeeRefund::query()
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereDate('refunded_at', '>=', $from)
                ->whereDate('refunded_at', '<=', $to)
                ->sum('amount');

            $discounts = (float) FeeAdjustment::query()
                ->where('school_id', $schoolId)
                ->where('type', 'discount')
                ->where('status', 'posted')
                ->whereDate('effective_on', '>=', $from)
                ->whereDate('effective_on', '<=', $to)
                ->sum('amount');

            $fines = (float) FeeAdjustment::query()
                ->where('school_id', $schoolId)
                ->where('type', 'fine')
                ->where('status', 'posted')
                ->whereDate('effective_on', '>=', $from)
                ->whereDate('effective_on', '<=', $to)
                ->sum('amount');

            $billed = (float) FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->whereDate('issue_date', '>=', $from)
                ->whereDate('issue_date', '<=', $to)
                ->sum('total_amount');

            $outstanding = (float) FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('balance_amount', '>', 0)
                ->sum('balance_amount');

            $byMethod = FeePayment::query()
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->whereDate('paid_at', '>=', $from)
                ->whereDate('paid_at', '<=', $to)
                ->selectRaw('method, count(*) as count, sum(amount - COALESCE(refunded_amount,0)) as total')
                ->groupBy('method')
                ->get();

            return $this->success([
                'period' => ['from' => $from, 'to' => $to],
                'summary' => [
                    'billed' => round($billed, 2),
                    'collections' => round($collections, 2),
                    'refunds' => round($refunds, 2),
                    'net_collections' => round($collections - $refunds, 2),
                    'discounts' => round($discounts, 2),
                    'fines' => round($fines, 2),
                    'outstanding' => round($outstanding, 2),
                ],
                'collections_by_method' => $byMethod,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    protected function recalcInvoiceBalance(FeeInvoice $invoice): void
    {
        $netPaid = max(0, (float) FeePayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'completed')
            ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)')));

        // External refunds table (if used) also reduce net paid
        $refundedExtra = (float) FeeRefund::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'completed')
            ->sum('amount');
        $netPaid = max(0, $netPaid - $refundedExtra);

        $balance = max(0, (float) $invoice->total_amount - $netPaid);
        $invoice->balance_amount = $balance;
        if ($balance <= 0) {
            $invoice->status = 'paid';
        } elseif ($netPaid > 0) {
            $invoice->status = 'partial';
        } elseif ($invoice->due_date && $invoice->due_date->lt(now()->startOfDay())) {
            $invoice->status = 'overdue';
        } else {
            $invoice->status = 'unpaid';
        }
        $invoice->save();
    }

    /**
     * Placeholder for multi-invoice cascade; primary invoice is already linked & recalculated.
     */
    protected function cascadePaymentAllocation(
        int $schoolId,
        int $studentId,
        float $amount,
        int $primaryInvoiceId,
        string $methodLabel,
        string $ref
    ): void {
        unset($schoolId, $studentId, $amount, $primaryInvoiceId, $methodLabel, $ref);
    }

    // ─── Payment methods ──────────────────────────────────────────

    public function methodsIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureDefaultPaymentMethods($schoolId);

            $query = PaymentMethod::query()
                ->where('school_id', $schoolId)
                ->orderBy('sort_order')
                ->orderBy('name');

            if ($request->boolean('active_only')) {
                $query->where('is_active', true);
            }

            return $this->success(['payment_methods' => $query->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function methodsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'type' => ['nullable', 'string', 'in:cash,bank,mobile_money,cheque,card,other'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            ]);

            $item = PaymentMethod::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'type' => $data['type'] ?? 'other',
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            return $this->success(['payment_method' => $item], 'Payment method created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function methodsUpdate(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $item = PaymentMethod::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['nullable', 'string', 'max:40'],
                'type' => ['nullable', 'string', 'in:cash,bank,mobile_money,cheque,card,other'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            ]);

            $item->fill($data);
            $item->save();

            return $this->success(['payment_method' => $item->fresh()], 'Payment method updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function methodsDestroy(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $item = PaymentMethod::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $inUse = FeePayment::query()
                ->where('school_id', $schoolId)
                ->where(function ($q) use ($item) {
                    $q->where('method', $item->name)
                        ->orWhere('method', $item->code);
                })
                ->exists();

            if ($inUse) {
                $item->is_active = false;
                $item->save();

                return $this->success([
                    'payment_method' => $item,
                    'deactivated' => true,
                ], 'Payment method deactivated (in use by payments)');
            }

            $item->delete();

            return $this->success([], 'Payment method deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Student fees ledger ──────────────────────────────────────

    /**
     * Per-student fee summary for a year + term scope (term empty = whole year / All).
     * Billed from invoices (fallback: fee structure expected), paid, remaining balance, paid/unpaid status.
     */
    public function studentFeesIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString(); // paid|partial|unpaid|none|all
            $yearId = $request->integer('academic_year_id') ?: null;
            $termId = $request->filled('term_id') ? $request->integer('term_id') : null; // null = All terms
            $classroomId = $request->integer('classroom_id') ?: null;
            $scopeAllTerms = ! $request->filled('term_id');

            $year = $yearId
                ? AcademicYear::query()->where('school_id', $schoolId)->find($yearId)
                : (AcademicYear::query()->where('school_id', $schoolId)->where('is_current', true)->first()
                    ?? AcademicYear::query()->where('school_id', $schoolId)->orderByDesc('id')->first());

            if (! $year) {
                return $this->success([
                    'student_fees' => [],
                    'filters' => [
                        'academic_year_id' => null,
                        'term_id' => null,
                        'term_scope' => 'all',
                        'classroom_id' => $classroomId,
                        'status' => $status ?: 'all',
                    ],
                    'terms' => [],
                    'summary' => [
                        'students' => 0,
                        'total_billed' => 0,
                        'total_paid' => 0,
                        'total_balance' => 0,
                        'paid_count' => 0,
                        'unpaid_count' => 0,
                        'partial_count' => 0,
                    ],
                    'by_term' => [],
                ]);
            }

            $yearId = (int) $year->id;
            $terms = Term::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $yearId)
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get(['id', 'name', 'starts_at', 'ends_at']);

            // Students: prefer those enrolled in the selected year
            $enrollQuery = Enrollment::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $yearId)
                ->whereIn('status', ['active', 'promoted', 'repeated'])
                ->when($classroomId, fn ($b) => $b->where('classroom_id', $classroomId));

            $enrollmentByStudent = $enrollQuery
                ->with('classroom:id,name')
                ->orderByDesc('id')
                ->get()
                ->groupBy('student_id')
                ->map(fn ($g) => $g->first());

            $studentIdsFromEnroll = $enrollmentByStudent->keys()->map(fn ($id) => (int) $id)->all();
            $studentIdsFromInvoices = FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $yearId)
                ->when(! $scopeAllTerms, fn ($b) => $b->where(function ($q) use ($termId) {
                    $q->where('term_id', $termId)->orWhereNull('term_id');
                }))
                ->distinct()
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // Students with completed payments (even without invoices) must appear on the ledger
            $studentIdsFromPayments = FeePayment::query()
                ->where('school_id', $schoolId)
                ->where('status', 'completed')
                ->distinct()
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // Class filter: only enrolled in that class. Otherwise enrolled + invoices + payers.
            $candidateIds = $classroomId
                ? $studentIdsFromEnroll
                : array_values(array_unique(array_merge(
                    $studentIdsFromEnroll,
                    $studentIdsFromInvoices,
                    $studentIdsFromPayments
                )));

            $students = Student::query()
                ->where('school_id', $schoolId)
                ->when(
                    $candidateIds !== [],
                    fn ($b) => $b->whereIn('id', $candidateIds),
                    fn ($b) => $b->whereRaw('0 = 1')
                )
                ->when($q !== '', function ($builder) use ($q) {
                    $builder->where(function ($b) use ($q) {
                        $b->where('first_name', 'like', "%{$q}%")
                            ->orWhere('middle_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhere('admission_number', 'like', "%{$q}%");
                    });
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit(500)
                ->get();

            $studentIds = $students->pluck('id')->all();

            // Invoices in scope (year + optional term)
            $invoiceQuery = FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->whereIn('student_id', $studentIds ?: [0])
                ->where('academic_year_id', $yearId)
                ->when(! $scopeAllTerms, fn ($b) => $b->where(function ($q) use ($termId) {
                    $q->where('term_id', $termId)->orWhereNull('term_id');
                }));

            $invoices = $invoiceQuery->get([
                'id', 'student_id', 'academic_year_id', 'term_id',
                'total_amount', 'balance_amount', 'status',
            ]);

            $invoiceIds = $invoices->pluck('id')->map(fn ($id) => (int) $id)->all();
            $invoiceIdSet = array_fill_keys($invoiceIds, true);
            $invoiceAgg = $invoices->groupBy('student_id')->map(function ($group) {
                return [
                    'invoice_count' => $group->count(),
                    'total_billed' => (float) $group->sum('total_amount'),
                    'total_balance' => (float) $group->sum('balance_amount'),
                ];
            });

            /**
             * Industrial payment attribution for the selected year/term:
             * - Payment linked to an invoice in scope → count it
             * - Payment with no invoice_id (advance / unallocated) → always count
             *   so recorded payments appear even before billing/invoices exist
             */
            $paymentAgg = FeePayment::query()
                ->where('school_id', $schoolId)
                ->whereIn('student_id', $studentIds ?: [0])
                ->where('status', 'completed')
                ->get(['id', 'student_id', 'invoice_id', 'amount', 'refunded_amount'])
                ->groupBy('student_id')
                ->map(function ($group) use ($invoiceIdSet) {
                    $scoped = $group->filter(function ($p) use ($invoiceIdSet) {
                        if ($p->invoice_id === null || $p->invoice_id === '') {
                            return true; // student-level / unallocated payment
                        }

                        return isset($invoiceIdSet[(int) $p->invoice_id]);
                    });

                    $gross = (float) $scoped->sum('amount');
                    $refunded = (float) $scoped->sum(fn ($p) => (float) ($p->refunded_amount ?? 0));

                    return [
                        'payment_count' => $scoped->count(),
                        'total_paid' => max(0, $gross - $refunded),
                    ];
                });

            // Fee structures for expected amount fallback
            $structures = FeeStructure::query()
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $yearId)
                ->where('is_active', true)
                ->when(! $scopeAllTerms, function ($b) use ($termId) {
                    $b->where(function ($q) use ($termId) {
                        $q->whereNull('term_id')->orWhere('term_id', $termId);
                    });
                })
                ->get(['id', 'term_id', 'classroom_id', 'fee_category_id', 'amount']);

            // Per-term totals for breakdown chips (whole year view)
            $byTerm = [];
            foreach ($terms as $term) {
                $termInvs = FeeInvoice::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $yearId)
                    ->where(function ($q) use ($term) {
                        $q->where('term_id', $term->id)->orWhereNull('term_id');
                    })
                    ->when($classroomId, function ($b) use ($schoolId, $yearId, $classroomId) {
                        $ids = Enrollment::query()
                            ->where('school_id', $schoolId)
                            ->where('academic_year_id', $yearId)
                            ->where('classroom_id', $classroomId)
                            ->pluck('student_id');
                        $b->whereIn('student_id', $ids);
                    })
                    ->get(['id', 'total_amount', 'balance_amount', 'student_id']);

                $termInvIds = $termInvs->pluck('id')->map(fn ($id) => (int) $id)->all();
                $termPaidLinked = $termInvIds === []
                    ? 0.0
                    : (float) FeePayment::query()
                        ->where('school_id', $schoolId)
                        ->where('status', 'completed')
                        ->whereIn('invoice_id', $termInvIds)
                        ->sum(DB::raw('amount - COALESCE(refunded_amount,0)'));

                $termBilled = (float) $termInvs->sum('total_amount');
                $termBalance = max(0, $termBilled - $termPaidLinked);
                $byTerm[] = [
                    'term_id' => $term->id,
                    'term' => $term->name,
                    'total_billed' => round($termBilled, 2),
                    'total_paid' => round($termPaidLinked, 2),
                    'total_balance' => round($termBalance, 2),
                    'students_with_balance' => $termInvs->groupBy('student_id')
                        ->filter(fn ($g) => (float) $g->sum('balance_amount') > 0.009)
                        ->count(),
                ];
            }

            $rows = $students->map(function (Student $student) use (
                $invoiceAgg,
                $paymentAgg,
                $enrollmentByStudent,
                $structures,
                $scopeAllTerms,
                $termId
            ) {
                $enr = $enrollmentByStudent->get($student->id);
                $classId = $enr?->classroom_id;
                $inv = $invoiceAgg->get($student->id);
                $pay = $paymentAgg->get($student->id);

                $billedFromInvoices = (float) ($inv['total_billed'] ?? 0);
                $balanceFromInvoices = (float) ($inv['total_balance'] ?? 0);
                $paid = (float) ($pay['total_paid'] ?? 0);

                // Expected from structures when no invoices yet
                $expected = $structures
                    ->filter(function (FeeStructure $s) use ($classId, $scopeAllTerms, $termId) {
                        $classOk = $s->classroom_id === null || (int) $s->classroom_id === (int) $classId;
                        if (! $scopeAllTerms) {
                            $termOk = $s->term_id === null || (int) $s->term_id === (int) $termId;

                            return $classOk && $termOk;
                        }

                        return $classOk;
                    })
                    ->sum(fn (FeeStructure $s) => (float) $s->amount);

                // Billed = invoices if present, else fee-structure expectation for class
                $billed = $billedFromInvoices > 0.009 ? $billedFromInvoices : (float) $expected;

                // If invoice balances show payment activity but payments weren't linked, lift paid
                if ($billedFromInvoices > 0.009) {
                    $derivedPaid = max(0, $billedFromInvoices - $balanceFromInvoices);
                    if ($derivedPaid > $paid) {
                        $paid = $derivedPaid;
                    }
                }

                // Ledger identity: remaining = billed − paid (includes unallocated payments)
                $remaining = max(0, $billed - $paid);

                $feeStatus = 'none';
                if ($billed > 0.009 || $paid > 0.009) {
                    if ($billed <= 0.009 && $paid > 0.009) {
                        // Advance payment, nothing billed yet
                        $feeStatus = 'paid';
                        $remaining = 0.0;
                    } elseif ($remaining <= 0.009) {
                        $feeStatus = 'paid';
                    } elseif ($paid > 0.009) {
                        $feeStatus = 'partial';
                    } else {
                        $feeStatus = 'unpaid';
                    }
                }

                return [
                    'student_id' => $student->id,
                    'admission_number' => $student->admission_number,
                    'full_name' => $student->full_name,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'status' => $student->status,
                    'classroom_id' => $classId,
                    'classroom' => $enr?->classroom ? [
                        'id' => $enr->classroom->id,
                        'name' => $enr->classroom->name,
                    ] : null,
                    'invoice_count' => (int) ($inv['invoice_count'] ?? 0),
                    'payment_count' => (int) ($pay['payment_count'] ?? 0),
                    'expected_amount' => round((float) $expected, 2),
                    'total_billed' => round($billed, 2),
                    'total_paid' => round($paid, 2),
                    'total_balance' => round($remaining, 2),
                    'remaining' => round($remaining, 2),
                    'fee_status' => $feeStatus,
                    'is_paid' => $feeStatus === 'paid',
                ];
            });

            if ($status !== '' && $status !== 'all') {
                $rows = $rows->filter(fn ($r) => $r['fee_status'] === $status)->values();
            }

            $paidCount = $rows->where('fee_status', 'paid')->count();
            $unpaidCount = $rows->where('fee_status', 'unpaid')->count();
            $partialCount = $rows->where('fee_status', 'partial')->count();

            return $this->success([
                'student_fees' => $rows->values(),
                'filters' => [
                    'academic_year_id' => $yearId,
                    'academic_year' => ['id' => $year->id, 'name' => $year->name],
                    'term_id' => $scopeAllTerms ? null : $termId,
                    'term_scope' => $scopeAllTerms ? 'all' : 'term',
                    'classroom_id' => $classroomId,
                    'status' => $status !== '' ? $status : 'all',
                    'q' => $q,
                ],
                'terms' => $terms->map(fn (Term $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                ])->values(),
                'summary' => [
                    'students' => $rows->count(),
                    'total_billed' => round($rows->sum('total_billed'), 2),
                    'total_paid' => round($rows->sum('total_paid'), 2),
                    'total_balance' => round($rows->sum('total_balance'), 2),
                    'paid_count' => $paidCount,
                    'unpaid_count' => $unpaidCount,
                    'partial_count' => $partialCount,
                ],
                'by_term' => $byTerm,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function studentFeesShow(Request $request, int $id)
    {
        try {
            $schoolId = $this->schoolId($request);
            $student = Student::query()
                ->where('school_id', $schoolId)
                ->findOrFail($id);

            $yearId = $request->integer('academic_year_id') ?: null;
            $year = $yearId
                ? AcademicYear::query()->where('school_id', $schoolId)->find($yearId)
                : (AcademicYear::query()->where('school_id', $schoolId)->where('is_current', true)->first()
                    ?? AcademicYear::query()->where('school_id', $schoolId)->orderByDesc('id')->first());

            $invoiceQuery = FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $id)
                ->with(['items.category', 'academicYear', 'term'])
                ->orderByDesc('id');

            if ($year) {
                $invoiceQuery->where('academic_year_id', $year->id);
            }

            $invoices = $invoiceQuery->get();

            $payments = FeePayment::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $id)
                ->with('invoice.academicYear', 'invoice.term')
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->get();

            $completedPayments = $payments->where('status', 'completed');
            $invoiceIds = $invoices->pluck('id')->map(fn ($i) => (int) $i)->all();
            $invoiceIdSet = array_fill_keys($invoiceIds, true);

            // Count: (1) payments on year invoices + (2) unallocated student payments
            $scopedPayments = $completedPayments->filter(function ($p) use ($invoiceIdSet, $year) {
                if ($p->invoice_id === null || $p->invoice_id === '') {
                    return true;
                }
                if (isset($invoiceIdSet[(int) $p->invoice_id])) {
                    return true;
                }
                // Payment on invoice from another year — exclude when year is scoped
                if ($year && $p->invoice && (int) $p->invoice->academic_year_id !== (int) $year->id) {
                    return false;
                }

                return ! $year; // no year filter → include
            });

            $totalPaid = max(0, (float) $scopedPayments->sum(function ($p) {
                return (float) $p->amount - (float) ($p->refunded_amount ?? 0);
            }));

            $totalBilledFromInvoices = (float) $invoices->sum('total_amount');

            // Expected from fee structure if no invoices
            $enrollment = Enrollment::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $id)
                ->when($year, fn ($q) => $q->where('academic_year_id', $year->id))
                ->whereIn('status', ['active', 'promoted', 'repeated'])
                ->orderByDesc('id')
                ->first();

            $expected = 0.0;
            if ($year) {
                $expected = (float) FeeStructure::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $year->id)
                    ->where('is_active', true)
                    ->where(function ($q) use ($enrollment) {
                        $q->whereNull('classroom_id');
                        if ($enrollment?->classroom_id) {
                            $q->orWhere('classroom_id', $enrollment->classroom_id);
                        }
                    })
                    ->sum('amount');
            }

            $totalBilled = $totalBilledFromInvoices > 0.009
                ? $totalBilledFromInvoices
                : $expected;

            $totalBalance = max(0, $totalBilled - $totalPaid);

            // Breakdown by term for the year
            $terms = $year
                ? Term::query()
                    ->where('school_id', $schoolId)
                    ->where('academic_year_id', $year->id)
                    ->orderBy('starts_at')
                    ->get(['id', 'name'])
                : collect();

            $byTerm = $terms->map(function (Term $term) use ($invoices, $completedPayments, $enrollment, $schoolId, $year) {
                $termInvs = $invoices->filter(fn ($i) => (int) $i->term_id === (int) $term->id
                    || $i->term_id === null);
                $ids = $termInvs->pluck('id')->map(fn ($i) => (int) $i)->all();
                $idSet = array_fill_keys($ids, true);
                $billedInv = (float) $termInvs->sum('total_amount');

                $structureBilled = $year
                    ? (float) FeeStructure::query()
                        ->where('school_id', $schoolId)
                        ->where('academic_year_id', $year->id)
                        ->where('is_active', true)
                        ->where(function ($q) use ($term) {
                            $q->whereNull('term_id')->orWhere('term_id', $term->id);
                        })
                        ->where(function ($q) use ($enrollment) {
                            $q->whereNull('classroom_id');
                            if ($enrollment?->classroom_id) {
                                $q->orWhere('classroom_id', $enrollment->classroom_id);
                            }
                        })
                        ->sum('amount')
                    : 0.0;

                $billed = $billedInv > 0.009 ? $billedInv : $structureBilled;

                $paid = (float) $completedPayments
                    ->filter(fn ($p) => $p->invoice_id && isset($idSet[(int) $p->invoice_id]))
                    ->sum(fn ($p) => (float) $p->amount - (float) ($p->refunded_amount ?? 0));

                // Unallocated payments only attributed on whole-year summary, not per term
                $balance = max(0, $billed - $paid);
                $feeStatus = 'none';
                if ($billed > 0.009) {
                    $feeStatus = $balance <= 0.009 ? 'paid' : ($paid > 0.009 ? 'partial' : 'unpaid');
                }

                return [
                    'term_id' => $term->id,
                    'term' => $term->name,
                    'total_billed' => round($billed, 2),
                    'total_paid' => round($paid, 2),
                    'total_balance' => round($balance, 2),
                    'fee_status' => $feeStatus,
                ];
            })->values();

            // Whole year status
            $yearStatus = 'none';
            if ($totalBilled > 0.009 || $totalPaid > 0.009) {
                if ($totalBilled <= 0.009 && $totalPaid > 0.009) {
                    $yearStatus = 'paid';
                    $totalBalance = 0;
                } elseif ($totalBalance <= 0.009) {
                    $yearStatus = 'paid';
                } elseif ($totalPaid > 0.009) {
                    $yearStatus = 'partial';
                } else {
                    $yearStatus = 'unpaid';
                }
            }

            return $this->success([
                'student' => [
                    'id' => $student->id,
                    'admission_number' => $student->admission_number,
                    'full_name' => $student->full_name,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'status' => $student->status,
                    'classroom' => $enrollment?->classroom_id
                        ? optional(Classroom::query()->find($enrollment->classroom_id))->only(['id', 'name'])
                        : null,
                ],
                'academic_year' => $year ? [
                    'id' => $year->id,
                    'name' => $year->name,
                ] : null,
                'summary' => [
                    'total_billed' => round($totalBilled, 2),
                    'total_paid' => round($totalPaid, 2),
                    'total_balance' => round(max(0, $totalBalance), 2),
                    'expected_amount' => round($expected, 2),
                    'invoice_count' => $invoices->count(),
                    'payment_count' => $scopedPayments->count(),
                    'fee_status' => $yearStatus,
                ],
                'by_term' => $byTerm,
                'invoices' => $invoices,
                'payments' => $payments,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /**
     * Seed common Tanzania school payment methods when none exist yet.
     */
    protected function ensureDefaultPaymentMethods(int $schoolId): void
    {
        if (PaymentMethod::query()->where('school_id', $schoolId)->exists()) {
            return;
        }

        $defaults = [
            ['name' => 'Cash', 'code' => 'CASH', 'type' => 'cash', 'sort_order' => 10],
            ['name' => 'Bank Transfer', 'code' => 'BANK', 'type' => 'bank', 'sort_order' => 20],
            ['name' => 'M-Pesa', 'code' => 'MPESA', 'type' => 'mobile_money', 'sort_order' => 30],
            ['name' => 'Tigo Pesa', 'code' => 'TIGO', 'type' => 'mobile_money', 'sort_order' => 40],
            ['name' => 'Airtel Money', 'code' => 'AIRTEL', 'type' => 'mobile_money', 'sort_order' => 50],
            ['name' => 'HaloPesa', 'code' => 'HALO', 'type' => 'mobile_money', 'sort_order' => 60],
            ['name' => 'Cheque', 'code' => 'CHEQUE', 'type' => 'cheque', 'sort_order' => 70],
        ];

        foreach ($defaults as $row) {
            PaymentMethod::query()->create([
                'school_id' => $schoolId,
                'name' => $row['name'],
                'code' => $row['code'],
                'type' => $row['type'],
                'is_active' => true,
                'sort_order' => $row['sort_order'],
            ]);
        }
    }
}
