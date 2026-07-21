<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\ResolvesSchool;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CashMovement;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Industrial double-entry accounting + cashbook for school tenants.
 */
class AccountingController extends Controller
{
    use ApiResponds, ResolvesSchool;

    // ── Chart of Accounts ─────────────────────────────────────────

    public function coaIndex(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureDefaultCoa($schoolId);

            $query = ChartOfAccount::query()
                ->where('school_id', $schoolId)
                ->orderBy('code');

            if ($request->string('type')->toString() !== '') {
                $query->where('type', $request->string('type')->toString());
            }
            if ($request->boolean('active_only')) {
                $query->where('is_active', true);
            }

            return $this->success(['accounts' => $query->get()]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function coaStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'code' => ['required', 'string', 'max:30'],
                'name' => ['required', 'string', 'max:120'],
                'type' => ['required', 'in:asset,liability,equity,income,expense'],
                'subtype' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $item = ChartOfAccount::query()->create([
                'school_id' => $schoolId,
                ...$data,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success(['account' => $item], 'Account created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function coaUpdate(Request $request, int $id)
    {
        try {
            $item = ChartOfAccount::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'code' => ['sometimes', 'string', 'max:30'],
                'name' => ['sometimes', 'string', 'max:120'],
                'type' => ['sometimes', 'in:asset,liability,equity,income,expense'],
                'subtype' => ['nullable', 'string', 'max:40'],
                'description' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $item->fill($data);
            $item->save();

            return $this->success(['account' => $item->fresh()], 'Account updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Bank accounts ─────────────────────────────────────────────

    public function banksIndex(Request $request)
    {
        try {
            $items = BankAccount::query()
                ->where('school_id', $this->schoolId($request))
                ->with('coa:id,code,name')
                ->orderBy('name')
                ->get()
                ->map(function (BankAccount $b) {
                    $movements = CashMovement::query()
                        ->where('bank_account_id', $b->id)
                        ->where('status', 'posted')
                        ->get();
                    $in = (float) $movements->where('type', 'income')->sum('amount');
                    $out = (float) $movements->where('type', 'expense')->sum('amount');
                    $balance = (float) $b->opening_balance + $in - $out;

                    return array_merge($b->toArray(), [
                        'current_balance' => round($balance, 2),
                    ]);
                });

            return $this->success(['bank_accounts' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function banksStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'bank_name' => ['nullable', 'string', 'max:120'],
                'account_number' => ['nullable', 'string', 'max:80'],
                'currency' => ['nullable', 'string', 'size:3'],
                'opening_balance' => ['nullable', 'numeric'],
                'coa_id' => ['nullable', 'integer'],
                'notes' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $item = BankAccount::query()->create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'currency' => $data['currency'] ?? 'TZS',
                'opening_balance' => $data['opening_balance'] ?? 0,
                'coa_id' => $data['coa_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->success(['bank_account' => $item], 'Bank account created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function banksUpdate(Request $request, int $id)
    {
        try {
            $item = BankAccount::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'bank_name' => ['nullable', 'string', 'max:120'],
                'account_number' => ['nullable', 'string', 'max:80'],
                'currency' => ['nullable', 'string', 'size:3'],
                'opening_balance' => ['nullable', 'numeric'],
                'coa_id' => ['nullable', 'integer'],
                'notes' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $item->fill($data);
            $item->save();

            return $this->success(['bank_account' => $item->fresh()], 'Bank account updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Budgets ───────────────────────────────────────────────────

    public function budgetsIndex(Request $request)
    {
        try {
            $items = Budget::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['lines.coa', 'academicYear:id,name'])
                ->orderByDesc('id')
                ->get()
                ->map(function (Budget $b) {
                    $total = (float) $b->lines->sum('amount');

                    return array_merge($b->toArray(), ['total_amount' => round($total, 2)]);
                });

            return $this->success(['budgets' => $items]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function budgetsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'academic_year_id' => ['nullable', 'integer'],
                'fiscal_year' => ['nullable', 'integer'],
                'status' => ['nullable', 'in:draft,active,closed'],
                'notes' => ['nullable', 'string'],
                'lines' => ['nullable', 'array'],
                'lines.*.label' => ['required_with:lines', 'string'],
                'lines.*.amount' => ['required_with:lines', 'numeric', 'min:0'],
                'lines.*.coa_id' => ['nullable', 'integer'],
                'lines.*.period' => ['nullable', 'string'],
            ]);

            $budget = DB::transaction(function () use ($schoolId, $data) {
                $budget = Budget::query()->create([
                    'school_id' => $schoolId,
                    'name' => $data['name'],
                    'academic_year_id' => $data['academic_year_id'] ?? null,
                    'fiscal_year' => $data['fiscal_year'] ?? (int) now()->format('Y'),
                    'status' => $data['status'] ?? 'draft',
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($data['lines'] ?? [] as $line) {
                    BudgetLine::query()->create([
                        'budget_id' => $budget->id,
                        'coa_id' => $line['coa_id'] ?? null,
                        'label' => $line['label'],
                        'amount' => $line['amount'],
                        'period' => $line['period'] ?? 'annual',
                    ]);
                }

                return $budget->load(['lines.coa', 'academicYear']);
            });

            return $this->success(['budget' => $budget], 'Budget created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function budgetsUpdate(Request $request, int $id)
    {
        try {
            $budget = Budget::query()
                ->where('school_id', $this->schoolId($request))
                ->findOrFail($id);

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'status' => ['sometimes', 'in:draft,active,closed'],
                'notes' => ['nullable', 'string'],
                'fiscal_year' => ['nullable', 'integer'],
                'academic_year_id' => ['nullable', 'integer'],
            ]);

            $budget->fill($data);
            $budget->save();

            return $this->success(['budget' => $budget->fresh()->load(['lines.coa'])], 'Budget updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Cash movements: income / expenses / cashbook ──────────────

    public function cashIndex(Request $request)
    {
        try {
            $type = $request->string('type')->toString(); // income|expense|''
            $query = CashMovement::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['bankAccount:id,name', 'coa:id,code,name'])
                ->orderByDesc('occurred_on')
                ->orderByDesc('id');

            if ($type !== '') {
                $query->where('type', $type);
            }
            if ($request->date('from')) {
                $query->whereDate('occurred_on', '>=', $request->date('from'));
            }
            if ($request->date('to')) {
                $query->whereDate('occurred_on', '<=', $request->date('to'));
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            $income = (float) CashMovement::query()
                ->where('school_id', $this->schoolId($request))
                ->where('type', 'income')
                ->where('status', 'posted')
                ->sum('amount');
            $expense = (float) CashMovement::query()
                ->where('school_id', $this->schoolId($request))
                ->where('type', 'expense')
                ->where('status', 'posted')
                ->sum('amount');

            return $this->success([
                'movements' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
                'summary' => [
                    'total_income' => round($income, 2),
                    'total_expense' => round($expense, 2),
                    'net' => round($income - $expense, 2),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function cashStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureDefaultCoa($schoolId);

            $data = $request->validate([
                'type' => ['required', 'in:income,expense'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'occurred_on' => ['nullable', 'date'],
                'payee' => ['nullable', 'string', 'max:255'],
                'method' => ['nullable', 'string', 'max:50'],
                'description' => ['nullable', 'string', 'max:255'],
                'bank_account_id' => ['nullable', 'integer'],
                'coa_id' => ['nullable', 'integer'],
                'reference' => ['nullable', 'string', 'max:50'],
            ]);

            $item = DB::transaction(function () use ($schoolId, $data, $request) {
                $ref = $data['reference'] ?? (strtoupper($data['type'] === 'income' ? 'INC' : 'EXP').'-'.strtoupper(Str::random(6)));
                $date = $data['occurred_on'] ?? now()->toDateString();
                $amount = (float) $data['amount'];

                $item = CashMovement::query()->create([
                    'school_id' => $schoolId,
                    'type' => $data['type'],
                    'amount' => $amount,
                    'occurred_on' => $date,
                    'payee' => $data['payee'] ?? null,
                    'method' => $data['method'] ?? 'cash',
                    'description' => $data['description'] ?? null,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'coa_id' => $data['coa_id'] ?? null,
                    'reference' => $ref,
                    'status' => 'posted',
                ]);

                // Double-entry: income Dr cash/bank Cr income; expense Dr expense Cr cash/bank
                $cashCoa = $this->resolveCashCoaId($schoolId, $data['bank_account_id'] ?? null, $data['method'] ?? 'cash');
                $pnlCoa = ! empty($data['coa_id'])
                    ? (int) $data['coa_id']
                    : $this->defaultPnlCoaId($schoolId, $data['type']);

                if ($cashCoa && $pnlCoa) {
                    $memo = trim(($data['description'] ?? '').' '.$ref);
                    $entry = JournalEntry::query()->create([
                        'school_id' => $schoolId,
                        'entry_number' => 'JE-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)),
                        'entry_date' => $date,
                        'memo' => $memo !== '' ? $memo : ucfirst($data['type']).' '.$ref,
                        'source' => 'cash_'.$data['type'],
                        'status' => 'posted',
                        'created_by' => $request->user()?->id,
                    ]);

                    if ($data['type'] === 'income') {
                        JournalLine::query()->create([
                            'journal_entry_id' => $entry->id,
                            'coa_id' => $cashCoa,
                            'debit' => $amount,
                            'credit' => 0,
                            'description' => $data['payee'] ?? 'Cash receipt',
                        ]);
                        JournalLine::query()->create([
                            'journal_entry_id' => $entry->id,
                            'coa_id' => $pnlCoa,
                            'debit' => 0,
                            'credit' => $amount,
                            'description' => $data['description'] ?? 'Income',
                        ]);
                    } else {
                        JournalLine::query()->create([
                            'journal_entry_id' => $entry->id,
                            'coa_id' => $pnlCoa,
                            'debit' => $amount,
                            'credit' => 0,
                            'description' => $data['description'] ?? 'Expense',
                        ]);
                        JournalLine::query()->create([
                            'journal_entry_id' => $entry->id,
                            'coa_id' => $cashCoa,
                            'debit' => 0,
                            'credit' => $amount,
                            'description' => $data['payee'] ?? 'Cash payment',
                        ]);
                    }
                }

                return $item->load(['bankAccount', 'coa']);
            });

            return $this->success([
                'movement' => $item,
            ], ucfirst($data['type']).' recorded', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    protected function resolveCashCoaId(int $schoolId, ?int $bankAccountId, string $method): ?int
    {
        if ($bankAccountId) {
            $bank = BankAccount::query()
                ->where('school_id', $schoolId)
                ->find($bankAccountId);
            if ($bank?->coa_id) {
                return (int) $bank->coa_id;
            }
        }

        $code = in_array($method, ['bank', 'cheque', 'card'], true) ? '1100' : '1000';

        return ChartOfAccount::query()
            ->where('school_id', $schoolId)
            ->where('code', $code)
            ->value('id');
    }

    protected function defaultPnlCoaId(int $schoolId, string $type): ?int
    {
        $code = $type === 'income' ? '4100' : '5900';

        return ChartOfAccount::query()
            ->where('school_id', $schoolId)
            ->where('code', $code)
            ->value('id');
    }

    // ── Journal entries ───────────────────────────────────────────

    public function journalsIndex(Request $request)
    {
        try {
            $query = JournalEntry::query()
                ->where('school_id', $this->schoolId($request))
                ->with(['lines.account'])
                ->orderByDesc('entry_date')
                ->orderByDesc('id');

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'entries' => $page->items(),
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

    public function journalsStore(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $data = $request->validate([
                'entry_date' => ['required', 'date'],
                'memo' => ['nullable', 'string', 'max:255'],
                'lines' => ['required', 'array', 'min:2'],
                'lines.*.coa_id' => ['required', 'integer'],
                'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.description' => ['nullable', 'string'],
            ]);

            $debit = 0.0;
            $credit = 0.0;
            foreach ($data['lines'] as $line) {
                $debit += (float) ($line['debit'] ?? 0);
                $credit += (float) ($line['credit'] ?? 0);
            }
            if (abs($debit - $credit) > 0.01) {
                return $this->error('Journal entry is not balanced. Debits must equal credits.', 422);
            }
            if ($debit <= 0) {
                return $this->error('Entry total must be greater than zero.', 422);
            }

            $entry = DB::transaction(function () use ($schoolId, $data, $request) {
                $entry = JournalEntry::query()->create([
                    'school_id' => $schoolId,
                    'entry_number' => 'JE-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)),
                    'entry_date' => $data['entry_date'],
                    'memo' => $data['memo'] ?? null,
                    'source' => 'manual',
                    'status' => 'posted',
                    'created_by' => $request->user()?->id,
                ]);

                foreach ($data['lines'] as $line) {
                    $d = (float) ($line['debit'] ?? 0);
                    $c = (float) ($line['credit'] ?? 0);
                    if ($d <= 0 && $c <= 0) {
                        continue;
                    }
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'coa_id' => $line['coa_id'],
                        'debit' => $d,
                        'credit' => $c,
                        'description' => $line['description'] ?? null,
                    ]);
                }

                return $entry->load(['lines.account']);
            });

            return $this->success(['entry' => $entry], 'Journal entry posted', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Ledger & statements ───────────────────────────────────────

    public function ledger(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $coaId = $request->integer('coa_id');
            if (! $coaId) {
                return $this->error('coa_id is required', 422);
            }

            $account = ChartOfAccount::query()
                ->where('school_id', $schoolId)
                ->findOrFail($coaId);

            $from = $request->date('from');
            $to = $request->date('to');

            $query = JournalLine::query()
                ->where('coa_id', $coaId)
                ->whereHas('entry', function ($e) use ($schoolId, $from, $to) {
                    $e->where('school_id', $schoolId)->where('status', 'posted');
                    if ($from) {
                        $e->whereDate('entry_date', '>=', $from);
                    }
                    if ($to) {
                        $e->whereDate('entry_date', '<=', $to);
                    }
                })
                ->with(['entry:id,entry_number,entry_date,memo'])
                ->orderBy('id');

            $lines = $query->get();
            $running = 0.0;
            $rows = [];
            foreach ($lines as $line) {
                // Assets/expenses: debit increases; liabilities/equity/income: credit increases
                $delta = in_array($account->type, ['asset', 'expense'], true)
                    ? ((float) $line->debit - (float) $line->credit)
                    : ((float) $line->credit - (float) $line->debit);
                $running += $delta;
                $rows[] = [
                    'id' => $line->id,
                    'entry_date' => optional($line->entry?->entry_date)?->toDateString(),
                    'entry_number' => $line->entry?->entry_number,
                    'memo' => $line->entry?->memo,
                    'description' => $line->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'balance' => round($running, 2),
                ];
            }

            return $this->success([
                'account' => $account,
                'lines' => $rows,
                'closing_balance' => round($running, 2),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function trialBalance(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $this->ensureDefaultCoa($schoolId);

            $accounts = ChartOfAccount::query()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get();

            $rows = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($accounts as $acc) {
                $sums = JournalLine::query()
                    ->where('coa_id', $acc->id)
                    ->whereHas('entry', fn ($e) => $e->where('school_id', $schoolId)->where('status', 'posted'))
                    ->selectRaw('COALESCE(sum(debit),0) as d, COALESCE(sum(credit),0) as c')
                    ->first();
                $d = (float) ($sums->d ?? 0);
                $c = (float) ($sums->c ?? 0);
                if ($d == 0 && $c == 0) {
                    continue;
                }
                $rows[] = [
                    'coa_id' => $acc->id,
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'type' => $acc->type,
                    'debit' => round($d, 2),
                    'credit' => round($c, 2),
                ];
                $totalDebit += $d;
                $totalCredit += $c;
            }

            return $this->success([
                'rows' => $rows,
                'totals' => [
                    'debit' => round($totalDebit, 2),
                    'credit' => round($totalCredit, 2),
                    'balanced' => abs($totalDebit - $totalCredit) < 0.02,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function profitAndLoss(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $from = $request->date('from') ?: now()->startOfYear()->toDateString();
            $to = $request->date('to') ?: now()->toDateString();

            $income = $this->accountTypeTotals($schoolId, 'income', $from, $to);
            $expense = $this->accountTypeTotals($schoolId, 'expense', $from, $to);

            $totalIncome = collect($income)->sum('amount');
            $totalExpense = collect($expense)->sum('amount');

            // Cash movements auto-post journals; figures below are for cashbook cross-check only
            $cashIncome = (float) CashMovement::query()
                ->where('school_id', $schoolId)->where('type', 'income')->where('status', 'posted')
                ->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to)
                ->sum('amount');
            $cashExpense = (float) CashMovement::query()
                ->where('school_id', $schoolId)->where('type', 'expense')->where('status', 'posted')
                ->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to)
                ->sum('amount');

            return $this->success([
                'period' => ['from' => $from, 'to' => $to],
                'income' => $income,
                'expenses' => $expense,
                'cash_income' => round($cashIncome, 2),
                'cash_expense' => round($cashExpense, 2),
                'total_income' => round($totalIncome, 2),
                'total_expenses' => round($totalExpense, 2),
                'net_profit' => round($totalIncome - $totalExpense, 2),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function balanceSheet(Request $request)
    {
        try {
            $schoolId = $this->schoolId($request);
            $asOf = $request->date('as_of') ?: now()->toDateString();

            $assets = $this->accountTypeBalances($schoolId, 'asset', $asOf);
            $liabilities = $this->accountTypeBalances($schoolId, 'liability', $asOf);
            $equity = $this->accountTypeBalances($schoolId, 'equity', $asOf);

            $totalAssets = collect($assets)->sum('balance');
            $totalLiab = collect($liabilities)->sum('balance');
            $totalEquity = collect($equity)->sum('balance');

            return $this->success([
                'as_of' => $asOf,
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'totals' => [
                    'assets' => round($totalAssets, 2),
                    'liabilities' => round($totalLiab, 2),
                    'equity' => round($totalEquity, 2),
                    'liabilities_and_equity' => round($totalLiab + $totalEquity, 2),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    protected function accountTypeTotals(int $schoolId, string $type, string $from, string $to): array
    {
        $accounts = ChartOfAccount::query()
            ->where('school_id', $schoolId)
            ->where('type', $type)
            ->where('is_active', true)
            ->get();

        $rows = [];
        foreach ($accounts as $acc) {
            $sums = JournalLine::query()
                ->where('coa_id', $acc->id)
                ->whereHas('entry', function ($e) use ($schoolId, $from, $to) {
                    $e->where('school_id', $schoolId)
                        ->where('status', 'posted')
                        ->whereDate('entry_date', '>=', $from)
                        ->whereDate('entry_date', '<=', $to);
                })
                ->selectRaw('COALESCE(sum(debit),0) as d, COALESCE(sum(credit),0) as c')
                ->first();
            $amount = $type === 'income'
                ? ((float) ($sums->c ?? 0) - (float) ($sums->d ?? 0))
                : ((float) ($sums->d ?? 0) - (float) ($sums->c ?? 0));
            if (abs($amount) < 0.005) {
                continue;
            }
            $rows[] = [
                'coa_id' => $acc->id,
                'code' => $acc->code,
                'name' => $acc->name,
                'amount' => round($amount, 2),
            ];
        }

        return $rows;
    }

    protected function accountTypeBalances(int $schoolId, string $type, string $asOf): array
    {
        $accounts = ChartOfAccount::query()
            ->where('school_id', $schoolId)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $rows = [];
        foreach ($accounts as $acc) {
            $sums = JournalLine::query()
                ->where('coa_id', $acc->id)
                ->whereHas('entry', function ($e) use ($schoolId, $asOf) {
                    $e->where('school_id', $schoolId)
                        ->where('status', 'posted')
                        ->whereDate('entry_date', '<=', $asOf);
                })
                ->selectRaw('COALESCE(sum(debit),0) as d, COALESCE(sum(credit),0) as c')
                ->first();
            $d = (float) ($sums->d ?? 0);
            $c = (float) ($sums->c ?? 0);
            $balance = in_array($type, ['asset', 'expense'], true) ? ($d - $c) : ($c - $d);
            if (abs($balance) < 0.005) {
                continue;
            }
            $rows[] = [
                'coa_id' => $acc->id,
                'code' => $acc->code,
                'name' => $acc->name,
                'balance' => round($balance, 2),
            ];
        }

        return $rows;
    }

    protected function ensureDefaultCoa(int $schoolId): void
    {
        if (ChartOfAccount::query()->where('school_id', $schoolId)->exists()) {
            return;
        }

        $defaults = [
            ['code' => '1000', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'cash'],
            ['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['code' => '1200', 'name' => 'Accounts Receivable (Fees)', 'type' => 'asset', 'subtype' => 'receivable'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'payable'],
            ['code' => '3000', 'name' => 'School Equity', 'type' => 'equity', 'subtype' => 'equity'],
            ['code' => '4000', 'name' => 'Fee Income', 'type' => 'income', 'subtype' => 'fees'],
            ['code' => '4100', 'name' => 'Other Income', 'type' => 'income', 'subtype' => 'other'],
            ['code' => '5000', 'name' => 'Salaries & Wages', 'type' => 'expense', 'subtype' => 'payroll'],
            ['code' => '5100', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'ops'],
            ['code' => '5200', 'name' => 'Supplies', 'type' => 'expense', 'subtype' => 'ops'],
            ['code' => '5300', 'name' => 'Maintenance', 'type' => 'expense', 'subtype' => 'ops'],
            ['code' => '5900', 'name' => 'Other Expenses', 'type' => 'expense', 'subtype' => 'other'],
        ];

        foreach ($defaults as $row) {
            ChartOfAccount::query()->create([
                'school_id' => $schoolId,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'is_system' => true,
                'is_active' => true,
            ]);
        }
    }
}
