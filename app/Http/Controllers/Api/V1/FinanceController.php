<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
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
                ->sum('amount');

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
                ->selectRaw('method, count(*) as count, sum(amount) as total')
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
        $paid = (float) FeePayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'completed')
            ->sum('amount');
        $refunded = (float) FeeRefund::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'completed')
            ->sum('amount');
        $netPaid = max(0, $paid - $refunded);
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
     * Per-student fee summary: billed, paid, balance + recent payments.
     */
    public function studentFeesIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString(); // paid|partial|unpaid|all

            $students = Student::query()
                ->where('school_id', $schoolId)
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
                ->limit(200)
                ->get();

            $studentIds = $students->pluck('id')->all();

            $invoiceAgg = FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->whereIn('student_id', $studentIds)
                ->selectRaw('student_id, COUNT(*) as invoice_count, COALESCE(SUM(total_amount),0) as total_billed, COALESCE(SUM(balance_amount),0) as total_balance')
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');

            $paymentAgg = FeePayment::query()
                ->where('school_id', $schoolId)
                ->whereIn('student_id', $studentIds)
                ->where('status', 'completed')
                ->selectRaw('student_id, COUNT(*) as payment_count, COALESCE(SUM(amount),0) as total_paid')
                ->groupBy('student_id')
                ->get()
                ->keyBy('student_id');

            $rows = $students->map(function (Student $student) use ($invoiceAgg, $paymentAgg) {
                $inv = $invoiceAgg->get($student->id);
                $pay = $paymentAgg->get($student->id);
                $billed = (float) ($inv->total_billed ?? 0);
                $balance = (float) ($inv->total_balance ?? 0);
                $paid = (float) ($pay->total_paid ?? 0);

                $feeStatus = 'none';
                if ($billed > 0) {
                    if ($balance <= 0) {
                        $feeStatus = 'paid';
                    } elseif ($paid > 0) {
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
                    'invoice_count' => (int) ($inv->invoice_count ?? 0),
                    'payment_count' => (int) ($pay->payment_count ?? 0),
                    'total_billed' => round($billed, 2),
                    'total_paid' => round($paid, 2),
                    'total_balance' => round($balance, 2),
                    'fee_status' => $feeStatus,
                ];
            });

            if ($status !== '' && $status !== 'all') {
                $rows = $rows->filter(fn ($r) => $r['fee_status'] === $status)->values();
            }

            return $this->success([
                'student_fees' => $rows->values(),
                'summary' => [
                    'students' => $rows->count(),
                    'total_billed' => round($rows->sum('total_billed'), 2),
                    'total_paid' => round($rows->sum('total_paid'), 2),
                    'total_balance' => round($rows->sum('total_balance'), 2),
                ],
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

            $invoices = FeeInvoice::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $id)
                ->with(['items', 'academicYear', 'term'])
                ->orderByDesc('id')
                ->get();

            $payments = FeePayment::query()
                ->where('school_id', $schoolId)
                ->where('student_id', $id)
                ->with('invoice')
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->get();

            $totalBilled = (float) $invoices->sum('total_amount');
            $totalBalance = (float) $invoices->sum('balance_amount');
            $totalPaid = (float) $payments->where('status', 'completed')->sum('amount');

            return $this->success([
                'student' => [
                    'id' => $student->id,
                    'admission_number' => $student->admission_number,
                    'full_name' => $student->full_name,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'status' => $student->status,
                ],
                'summary' => [
                    'total_billed' => round($totalBilled, 2),
                    'total_paid' => round($totalPaid, 2),
                    'total_balance' => round($totalBalance, 2),
                    'invoice_count' => $invoices->count(),
                    'payment_count' => $payments->count(),
                ],
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
