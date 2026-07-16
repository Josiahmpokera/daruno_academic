<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\FeeInvoice;
use App\Models\FeeInvoiceItem;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Student;
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

            $item = FeeStructure::query()->create([
                'school_id' => $this->schoolId($request),
                'currency' => $data['currency'] ?? 'TZS',
                'is_active' => $data['is_active'] ?? true,
                ...collect($data)->except(['currency', 'is_active'])->all(),
            ]);

            return $this->success([
                'structure' => $item->load(['category', 'academicYear', 'term', 'classroom']),
            ], 'Fee structure created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
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

            return $this->success(['payments' => $query->limit(100)->get()]);
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
                'reference' => ['nullable', 'string', 'max:100'],
                'paid_at' => ['nullable', 'date'],
            ]);

            $payment = DB::transaction(function () use ($schoolId, $data) {
                Student::query()->where('school_id', $schoolId)->findOrFail($data['student_id']);

                $invoice = null;
                if (! empty($data['invoice_id'])) {
                    $invoice = FeeInvoice::query()
                        ->where('school_id', $schoolId)
                        ->findOrFail($data['invoice_id']);
                }

                $payment = FeePayment::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $data['student_id'],
                    'invoice_id' => $invoice?->id,
                    'amount' => $data['amount'],
                    'method' => $data['method'] ?? 'cash',
                    'reference' => $data['reference'] ?? ('PAY-'.strtoupper(Str::random(8))),
                    'paid_at' => $data['paid_at'] ?? now(),
                    'status' => 'completed',
                ]);

                if ($invoice) {
                    $paid = (float) $invoice->payments()->where('status', 'completed')->sum('amount');
                    $balance = max(0, (float) $invoice->total_amount - $paid);
                    $invoice->balance_amount = $balance;
                    $invoice->status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                    $invoice->save();
                }

                return $payment->load(['student', 'invoice']);
            });

            return $this->success(['payment' => $payment], 'Payment recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
