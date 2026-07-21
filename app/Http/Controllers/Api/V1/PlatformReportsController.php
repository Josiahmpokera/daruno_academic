<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Super-admin platform reports — real aggregates from tenant / SaaS tables.
 */
class PlatformReportsController extends Controller
{
    use ApiResponds;

    /**
     * Catalog of available platform reports (for nav / discovery).
     */
    public function catalog()
    {
        try {
            return $this->success([
                'reports' => [
                    [
                        'key' => 'overview',
                        'label' => 'Reports overview',
                        'path' => '/platform/reports',
                        'description' => 'Platform KPIs and at-a-glance metrics',
                    ],
                    [
                        'key' => 'schools',
                        'label' => 'Schools report',
                        'path' => '/platform/reports/schools',
                        'description' => 'Tenants by status, region, source, and education level',
                    ],
                    [
                        'key' => 'subscriptions',
                        'label' => 'Subscriptions report',
                        'path' => '/platform/reports/subscriptions',
                        'description' => 'Onboarded tenants with real subscriptions by plan and status',
                    ],
                    [
                        'key' => 'revenue',
                        'label' => 'Revenue & billing',
                        'path' => '/platform/reports/revenue',
                        'description' => 'Platform invoices, paid revenue, and outstanding amounts',
                    ],
                    [
                        'key' => 'users',
                        'label' => 'Users & access',
                        'path' => '/platform/reports/users',
                        'description' => 'Platform and school users by role',
                    ],
                    [
                        'key' => 'growth',
                        'label' => 'Growth & adoption',
                        'path' => '/platform/reports/growth',
                        'description' => 'School and subscription growth over time',
                    ],
                    [
                        'key' => 'tanzania-registry',
                        'label' => 'Tanzania registry',
                        'path' => '/platform/reports/tanzania-registry',
                        'description' => 'Bulk-imported Tanzania primary school registry stats',
                    ],
                    [
                        'key' => 'audit',
                        'label' => 'Audit activity',
                        'path' => '/platform/reports/audit',
                        'description' => 'Recent super-admin and platform audit actions',
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function overview()
    {
        try {
            $realSubsBase = $this->realSubscriptionsQuery();

            $schoolsTotal = School::query()->count();
            $schoolsActive = School::query()->where('status', 'active')->count();
            $schoolsPending = School::query()->where('status', 'pending')->count();
            $schoolsSuspended = School::query()->where('status', 'suspended')->count();

            $subsActive = (clone $realSubsBase)->where('status', 'active')->count();
            $subsTrial = (clone $realSubsBase)->where('status', 'trial')->count();
            $subsTotal = (clone $realSubsBase)->count();

            $revenuePaid = (float) PlatformInvoice::query()->where('status', 'paid')->sum('amount');
            $revenueUnpaid = (float) PlatformInvoice::query()->where('status', 'unpaid')->sum('amount');
            $invoicesTotal = PlatformInvoice::query()->count();

            $usersTotal = User::query()->count();
            $platformUsers = User::query()->whereNull('school_id')->count();
            $schoolUsers = User::query()->whereNotNull('school_id')->count();

            $registryCount = School::query()
                ->where('settings->source', 'tanzania_bulk_import')
                ->count();

            $plansActive = SubscriptionPlan::query()->where('is_active', true)->count();

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'kpis' => [
                    'schools_total' => $schoolsTotal,
                    'schools_active' => $schoolsActive,
                    'schools_pending' => $schoolsPending,
                    'schools_suspended' => $schoolsSuspended,
                    'subscriptions_total' => $subsTotal,
                    'subscriptions_active' => $subsActive,
                    'subscriptions_trial' => $subsTrial,
                    'revenue_paid' => $revenuePaid,
                    'revenue_unpaid' => $revenueUnpaid,
                    'invoices_total' => $invoicesTotal,
                    'users_total' => $usersTotal,
                    'platform_users' => $platformUsers,
                    'school_users' => $schoolUsers,
                    'tanzania_registry' => $registryCount,
                    'plans_active' => $plansActive,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function schools(Request $request)
    {
        try {
            $status = $request->string('status')->toString();
            $country = $request->string('country')->toString();
            $q = $request->string('q')->toString();

            $base = School::query();
            if ($status !== '') {
                $base->where('status', $status);
            }
            if ($country !== '') {
                $base->where('country', $country);
            }
            if ($q !== '') {
                $base->where(function ($b) use ($q) {
                    $b->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('region', 'like', "%{$q}%")
                        ->orWhere('district', 'like', "%{$q}%");
                });
            }

            $byStatus = (clone $base)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['status' => $r->status ?: 'unknown', 'total' => (int) $r->total]);

            $byCountry = (clone $base)
                ->select('country', DB::raw('count(*) as total'))
                ->groupBy('country')
                ->orderByDesc('total')
                ->limit(20)
                ->get()
                ->map(fn ($r) => ['country' => $r->country ?: 'Unknown', 'total' => (int) $r->total]);

            $byRegion = (clone $base)
                ->whereNotNull('region')
                ->where('region', '!=', '')
                ->select('region', DB::raw('count(*) as total'))
                ->groupBy('region')
                ->orderByDesc('total')
                ->limit(25)
                ->get()
                ->map(fn ($r) => ['region' => $r->region, 'total' => (int) $r->total]);

            $bySource = (clone $base)
                ->select(DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.source')), 'manual') as source"), DB::raw('count(*) as total'))
                ->groupBy('source')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['source' => $r->source ?: 'manual', 'total' => (int) $r->total]);

            $withSubscription = (clone $base)->whereHas('subscriptions')->count();
            $withoutSubscription = (clone $base)->whereDoesntHave('subscriptions')->count();

            $rows = (clone $base)
                ->with(['currentSubscription.plan:id,name,code'])
                ->withCount('users')
                ->orderByDesc('id')
                ->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'total' => (clone $base)->count(),
                    'with_subscription' => $withSubscription,
                    'without_subscription' => $withoutSubscription,
                ],
                'by_status' => $byStatus,
                'by_country' => $byCountry,
                'by_region' => $byRegion,
                'by_source' => $bySource,
                'schools' => $rows->items(),
                'meta' => [
                    'current_page' => $rows->currentPage(),
                    'last_page' => $rows->lastPage(),
                    'per_page' => $rows->perPage(),
                    'total' => $rows->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function subscriptions(Request $request)
    {
        try {
            $status = $request->string('status')->toString();
            $query = $this->realSubscriptionsQuery()
                ->with([
                    'school:id,name,code,status,region,district,country',
                    'plan:id,name,code,price,currency,billing_cycle',
                ]);

            if ($status !== '') {
                $query->where('status', $status);
            }

            $byStatus = $this->realSubscriptionsQuery()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['status' => $r->status, 'total' => (int) $r->total]);

            $byPlan = $this->realSubscriptionsQuery()
                ->join('subscription_plans', 'subscription_plans.id', '=', 'school_subscriptions.plan_id')
                ->select(
                    'subscription_plans.id as plan_id',
                    'subscription_plans.name as plan_name',
                    'subscription_plans.code as plan_code',
                    DB::raw('count(*) as total')
                )
                ->groupBy('subscription_plans.id', 'subscription_plans.name', 'subscription_plans.code')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => [
                    'plan_id' => (int) $r->plan_id,
                    'plan_name' => $r->plan_name,
                    'plan_code' => $r->plan_code,
                    'total' => (int) $r->total,
                ]);

            $page = $query->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'total' => $this->realSubscriptionsQuery()->count(),
                    'active' => $this->realSubscriptionsQuery()->where('status', 'active')->count(),
                    'trial' => $this->realSubscriptionsQuery()->where('status', 'trial')->count(),
                    'cancelled' => $this->realSubscriptionsQuery()->where('status', 'cancelled')->count(),
                    'expired' => $this->realSubscriptionsQuery()->where('status', 'expired')->count(),
                ],
                'by_status' => $byStatus,
                'by_plan' => $byPlan,
                'subscriptions' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'note' => 'Excludes auto-created trials from Tanzania bulk school registry imports.',
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function revenue(Request $request)
    {
        try {
            $paid = (float) PlatformInvoice::query()->where('status', 'paid')->sum('amount');
            $unpaid = (float) PlatformInvoice::query()->where('status', 'unpaid')->sum('amount');
            $failed = (float) PlatformInvoice::query()->where('status', 'failed')->sum('amount');
            $refunded = (float) PlatformInvoice::query()->where('status', 'refunded')->sum('amount');

            $byStatus = PlatformInvoice::query()
                ->select('status', DB::raw('count(*) as count'), DB::raw('COALESCE(sum(amount),0) as amount'))
                ->groupBy('status')
                ->orderByDesc('amount')
                ->get()
                ->map(fn ($r) => [
                    'status' => $r->status,
                    'count' => (int) $r->count,
                    'amount' => (float) $r->amount,
                ]);

            $byCurrency = PlatformInvoice::query()
                ->select('currency', DB::raw('count(*) as count'), DB::raw('COALESCE(sum(amount),0) as amount'))
                ->groupBy('currency')
                ->get()
                ->map(fn ($r) => [
                    'currency' => $r->currency ?: 'USD',
                    'count' => (int) $r->count,
                    'amount' => (float) $r->amount,
                ]);

            $monthly = PlatformInvoice::query()
                ->select(
                    DB::raw("DATE_FORMAT(COALESCE(paid_at, issue_date, created_at), '%Y-%m') as month"),
                    DB::raw("sum(case when status = 'paid' then amount else 0 end) as paid"),
                    DB::raw("sum(case when status = 'unpaid' then amount else 0 end) as unpaid"),
                    DB::raw('count(*) as invoices')
                )
                ->groupBy('month')
                ->orderByDesc('month')
                ->limit(12)
                ->get()
                ->map(fn ($r) => [
                    'month' => $r->month,
                    'paid' => (float) $r->paid,
                    'unpaid' => (float) $r->unpaid,
                    'invoices' => (int) $r->invoices,
                ])
                ->reverse()
                ->values();

            $invoices = PlatformInvoice::query()
                ->with(['school:id,name,code', 'subscription.plan:id,name,code'])
                ->orderByDesc('id')
                ->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'paid' => $paid,
                    'unpaid' => $unpaid,
                    'failed' => $failed,
                    'refunded' => $refunded,
                    'invoice_count' => PlatformInvoice::query()->count(),
                ],
                'by_status' => $byStatus,
                'by_currency' => $byCurrency,
                'monthly' => $monthly,
                'invoices' => $invoices->items(),
                'meta' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                    'per_page' => $invoices->perPage(),
                    'total' => $invoices->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function users(Request $request)
    {
        try {
            $byRole = User::query()
                ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->select(
                    DB::raw("COALESCE(roles.name, 'unassigned') as role_name"),
                    DB::raw("COALESCE(roles.label, roles.name, 'Unassigned') as role_label"),
                    DB::raw('count(*) as total')
                )
                ->groupBy('role_name', 'role_label')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => [
                    'role' => $r->role_name,
                    'label' => $r->role_label,
                    'total' => (int) $r->total,
                ]);

            $platformUsers = User::query()->whereNull('school_id')->count();
            $schoolUsers = User::query()->whereNotNull('school_id')->count();

            $users = User::query()
                ->with(['role:id,name,label', 'school:id,name,code'])
                ->orderByDesc('id')
                ->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'total' => User::query()->count(),
                    'platform_users' => $platformUsers,
                    'school_users' => $schoolUsers,
                ],
                'by_role' => $byRole,
                'users' => $users->items(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function growth(Request $request)
    {
        try {
            $months = max(3, min(24, $request->integer('months', 12)));

            $schoolsByMonth = School::query()
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw('count(*) as total')
                )
                ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($r) => ['month' => $r->month, 'total' => (int) $r->total]);

            $subsByMonth = $this->realSubscriptionsQuery()
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw('count(*) as total')
                )
                ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($r) => ['month' => $r->month, 'total' => (int) $r->total]);

            $usersByMonth = User::query()
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw('count(*) as total')
                )
                ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($r) => ['month' => $r->month, 'total' => (int) $r->total]);

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'months' => $months,
                'schools_by_month' => $schoolsByMonth,
                'subscriptions_by_month' => $subsByMonth,
                'users_by_month' => $usersByMonth,
                'totals' => [
                    'schools' => School::query()->count(),
                    'subscriptions' => $this->realSubscriptionsQuery()->count(),
                    'users' => User::query()->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function tanzaniaRegistry(Request $request)
    {
        try {
            $base = School::query()->where('settings->source', 'tanzania_bulk_import');

            $byRegion = (clone $base)
                ->select('region', DB::raw('count(*) as total'))
                ->groupBy('region')
                ->orderByDesc('total')
                ->limit(40)
                ->get()
                ->map(fn ($r) => ['region' => $r->region ?: 'Unknown', 'total' => (int) $r->total]);

            $byDistrict = (clone $base)
                ->select('district', 'region', DB::raw('count(*) as total'))
                ->groupBy('district', 'region')
                ->orderByDesc('total')
                ->limit(50)
                ->get()
                ->map(fn ($r) => [
                    'district' => $r->district ?: 'Unknown',
                    'region' => $r->region ?: 'Unknown',
                    'total' => (int) $r->total,
                ]);

            $byStatus = (clone $base)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get()
                ->map(fn ($r) => ['status' => $r->status ?: 'unknown', 'total' => (int) $r->total]);

            $rows = (clone $base)
                ->orderBy('region')
                ->orderBy('district')
                ->orderBy('name')
                ->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'total' => (clone $base)->count(),
                    'regions' => (clone $base)->whereNotNull('region')->distinct('region')->count('region'),
                    'districts' => (clone $base)->whereNotNull('district')->distinct('district')->count('district'),
                ],
                'by_region' => $byRegion,
                'by_district' => $byDistrict,
                'by_status' => $byStatus,
                'schools' => $rows->items(),
                'meta' => [
                    'current_page' => $rows->currentPage(),
                    'last_page' => $rows->lastPage(),
                    'per_page' => $rows->perPage(),
                    'total' => $rows->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function audit(Request $request)
    {
        try {
            $byAction = AuditLog::query()
                ->select('action', DB::raw('count(*) as total'))
                ->groupBy('action')
                ->orderByDesc('total')
                ->limit(30)
                ->get()
                ->map(fn ($r) => ['action' => $r->action, 'total' => (int) $r->total]);

            $recentDays = max(7, min(90, $request->integer('days', 30)));
            $daily = AuditLog::query()
                ->select(
                    DB::raw('DATE(created_at) as day'),
                    DB::raw('count(*) as total')
                )
                ->where('created_at', '>=', now()->subDays($recentDays)->startOfDay())
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(fn ($r) => ['day' => $r->day, 'total' => (int) $r->total]);

            $logs = AuditLog::query()
                ->with(['user:id,name,email', 'school:id,name,code'])
                ->orderByDesc('id')
                ->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'total' => AuditLog::query()->count(),
                    'last_7_days' => AuditLog::query()->where('created_at', '>=', now()->subDays(7))->count(),
                    'last_30_days' => AuditLog::query()->where('created_at', '>=', now()->subDays(30))->count(),
                ],
                'by_action' => $byAction,
                'daily' => $daily,
                'logs' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Real onboarded subscriptions — exclude bulk-import free trials.
     */
    protected function realSubscriptionsQuery()
    {
        return SchoolSubscription::query()
            ->whereHas('school')
            ->where(function ($b) {
                $b->where('status', '!=', 'trial')
                    ->orWhereHas('school', function ($s) {
                        $s->where(function ($inner) {
                            $inner->whereNull('settings')
                                ->orWhereNull('settings->source')
                                ->orWhere('settings->source', '!=', 'tanzania_bulk_import');
                        });
                    });
            });
    }
}
