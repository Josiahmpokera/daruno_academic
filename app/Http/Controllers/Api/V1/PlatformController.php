<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class PlatformController extends Controller
{
    use ApiResponds;

    public function dashboard()
    {
        try {
            $schools = School::query()->count();
            $activeSchools = School::query()->where('status', 'active')->count();
            $pendingSchools = School::query()->where('status', 'pending')->count();
            $users = User::query()->count();
            $subscriptions = SchoolSubscription::query()->whereIn('status', ['active', 'trial'])->count();
            $revenue = PlatformInvoice::query()->where('status', 'paid')->sum('amount');
            $unpaid = PlatformInvoice::query()->where('status', 'unpaid')->sum('amount');

            $recentSchools = School::query()
                ->with(['currentSubscription.plan'])
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $recentAudits = AuditLog::query()
                ->with('user:id,name,email')
                ->orderByDesc('id')
                ->limit(8)
                ->get();

            $plans = SubscriptionPlan::query()
                ->withCount([
                    'subscriptions as subscriptions_count',
                    'subscriptions as active_subscriptions_count' => function ($q) {
                        $q->whereIn('status', ['active', 'trial']);
                    },
                ])
                ->orderBy('price')
                ->get();

            $mrr = 0.0;
            foreach ($plans as $plan) {
                $activeOnPlan = (int) ($plan->active_subscriptions_count ?? 0);
                $price = (float) $plan->price;
                if ($plan->billing_cycle === 'annual') {
                    $mrr += $activeOnPlan * ($price / 12);
                } else {
                    $mrr += $activeOnPlan * $price;
                }
            }

            $trialSubs = SchoolSubscription::query()->where('status', 'trial')->count();
            $expiredSubs = SchoolSubscription::query()->whereIn('status', ['expired', 'cancelled'])->count();

            return $this->success([
                'stats' => [
                    'schools' => $schools,
                    'active_schools' => $activeSchools,
                    'pending_schools' => $pendingSchools,
                    'users' => $users,
                    'active_subscriptions' => $subscriptions,
                    'trial_subscriptions' => $trialSubs,
                    'ended_subscriptions' => $expiredSubs,
                    'plans_count' => $plans->count(),
                    'paid_revenue' => (float) $revenue,
                    'unpaid_amount' => (float) $unpaid,
                    'estimated_mrr' => round($mrr, 2),
                    'estimated_arr' => round($mrr * 12, 2),
                ],
                'plan_mix' => $plans->map(function (SubscriptionPlan $plan) {
                    return [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'code' => $plan->code,
                        'billing_cycle' => $plan->billing_cycle,
                        'price' => (float) $plan->price,
                        'currency' => $plan->currency,
                        'is_active' => (bool) $plan->is_active,
                        'subscriptions_count' => (int) $plan->subscriptions_count,
                        'active_subscriptions_count' => (int) $plan->active_subscriptions_count,
                    ];
                })->values(),
                'recent_schools' => $recentSchools,
                'recent_audits' => $recentAudits,
                'architecture' => [
                    'layer' => 'SaaS Platform',
                    'capabilities' => [
                        'Tenant Management',
                        'Subscription Management',
                        'Billing',
                        'Authentication',
                        'Notifications',
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Schools (tenants) ─────────────────────────────────────────

    public function schoolsIndex(Request $request)
    {
        try {
            $q = $request->string('q')->toString();
            $status = $request->string('status')->toString();

            $query = School::query()
                ->with(['currentSubscription.plan'])
                ->withCount('users')
                ->orderByDesc('id');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('contact_email', 'like', "%{$q}%");
                });
            }
            if ($status !== '') {
                $query->where('status', $status);
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'schools' => $page->items(),
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

    public function schoolsStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'code' => ['nullable', 'string', 'max:50', 'unique:schools,code'],
                'contact_email' => ['nullable', 'email'],
                'contact_phone' => ['nullable', 'string', 'max:40'],
                'status' => ['nullable', 'in:pending,active,suspended,rejected'],
                'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
                'admin_name' => ['nullable', 'string', 'max:255'],
                'admin_email' => ['nullable', 'email', 'unique:users,email'],
                'admin_password' => ['nullable', 'string', 'min:8'],
            ]);

            $school = DB::transaction(function () use ($data, $request) {
                $code = $data['code'] ?? null;
                if (! $code) {
                    do {
                        $code = strtoupper(Str::random(6));
                    } while (School::query()->where('code', $code)->exists());
                }

                $school = School::query()->create([
                    'name' => $data['name'],
                    'code' => $code,
                    'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
                    'status' => $data['status'] ?? 'pending',
                    'contact_email' => $data['contact_email'] ?? null,
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'settings' => ['source' => 'platform'],
                ]);

                $planId = $data['plan_id'] ?? SubscriptionPlan::query()->where('is_active', true)->value('id');
                if ($planId) {
                    $plan = SubscriptionPlan::query()->find($planId);
                    SchoolSubscription::query()->create([
                        'school_id' => $school->id,
                        'plan_id' => $plan->id,
                        'status' => 'trial',
                        'starts_on' => now()->toDateString(),
                        'trial_ends_on' => now()->addDays($plan->trial_days ?: 14)->toDateString(),
                        'ends_on' => now()->addDays($plan->trial_days ?: 14)->toDateString(),
                        'auto_renew' => true,
                    ]);
                }

                if (! empty($data['admin_email']) && ! empty($data['admin_password'])) {
                    $role = Role::query()->where('name', 'school_admin')->first()
                        ?? Role::query()->where('name', 'school_owner')->first();
                    if ($role) {
                        User::query()->create([
                            'school_id' => $school->id,
                            'role_id' => $role->id,
                            'name' => $data['admin_name'] ?? 'School Admin',
                            'email' => $data['admin_email'],
                            'password' => Hash::make($data['admin_password']),
                        ]);
                    }
                }

                $this->audit($request, 'school.created', 'school', $school->id, [
                    'name' => $school->name,
                    'code' => $school->code,
                ]);

                return $school->load('currentSubscription.plan');
            });

            return $this->success(['school' => $school], 'School created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function schoolsUpdate(Request $request, int $id)
    {
        try {
            $school = School::query()->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'status' => ['sometimes', 'in:pending,active,suspended,rejected'],
                'contact_email' => ['sometimes', 'nullable', 'email'],
                'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'settings' => ['sometimes', 'array'],
            ]);

            $before = $school->only(array_keys($data));
            $school->fill($data);
            $school->save();

            $this->audit($request, 'school.updated', 'school', $school->id, [
                'before' => $before,
                'after' => $school->only(array_keys($data)),
            ]);

            return $this->success([
                'school' => $school->fresh()->load('currentSubscription.plan'),
            ], 'School updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Subscriptions ─────────────────────────────────────────────

    public function plansIndex()
    {
        try {
            $plans = SubscriptionPlan::query()
                ->withCount([
                    'subscriptions as subscriptions_count',
                    'subscriptions as active_subscriptions_count' => function ($q) {
                        $q->whereIn('status', ['active', 'trial']);
                    },
                    'subscriptions as trial_subscriptions_count' => function ($q) {
                        $q->where('status', 'trial');
                    },
                ])
                ->orderBy('price')
                ->orderBy('name')
                ->get()
                ->map(function (SubscriptionPlan $plan) {
                    return [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'code' => $plan->code,
                        'billing_cycle' => $plan->billing_cycle,
                        'price' => (float) $plan->price,
                        'currency' => $plan->currency ?? 'USD',
                        'trial_days' => (int) $plan->trial_days,
                        'max_students' => $plan->max_students,
                        'max_staff' => $plan->max_staff,
                        'features' => $plan->features ?? [],
                        'is_active' => (bool) $plan->is_active,
                        'subscriptions_count' => (int) $plan->subscriptions_count,
                        'active_subscriptions_count' => (int) $plan->active_subscriptions_count,
                        'trial_subscriptions_count' => (int) $plan->trial_subscriptions_count,
                        'created_at' => $plan->created_at,
                        'updated_at' => $plan->updated_at,
                    ];
                });

            $summary = [
                'total_plans' => $plans->count(),
                'active_plans' => $plans->where('is_active', true)->count(),
                'total_subscriptions' => $plans->sum('subscriptions_count'),
                'active_subscriptions' => $plans->sum('active_subscriptions_count'),
            ];

            return $this->success([
                'plans' => $plans->values(),
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function plansStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:50', 'unique:subscription_plans,code'],
                'billing_cycle' => ['required', 'in:monthly,annual'],
                'price' => ['required', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'size:3'],
                'trial_days' => ['nullable', 'integer', 'min:0'],
                'max_students' => ['nullable', 'integer', 'min:1'],
                'max_staff' => ['nullable', 'integer', 'min:1'],
                'features' => ['nullable', 'array'],
                'features.*' => ['string', 'max:80'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $code = Str::slug($data['code'], '_');

            $plan = SubscriptionPlan::query()->create([
                'name' => $data['name'],
                'code' => $code,
                'billing_cycle' => $data['billing_cycle'],
                'price' => $data['price'],
                'currency' => strtoupper($data['currency'] ?? 'USD'),
                'trial_days' => $data['trial_days'] ?? 14,
                'max_students' => $data['max_students'] ?? null,
                'max_staff' => $data['max_staff'] ?? null,
                'features' => $data['features'] ?? [],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->audit($request, 'plan.created', 'subscription_plan', $plan->id, [
                'name' => $plan->name,
                'code' => $plan->code,
            ]);

            return $this->success([
                'plan' => $this->serializePlan($plan->fresh()->loadCount([
                    'subscriptions as subscriptions_count',
                    'subscriptions as active_subscriptions_count' => function ($q) {
                        $q->whereIn('status', ['active', 'trial']);
                    },
                ])),
            ], 'Plan created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function plansUpdate(Request $request, int $id)
    {
        try {
            $plan = SubscriptionPlan::query()->findOrFail($id);
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'code' => ['sometimes', 'string', 'max:50', 'unique:subscription_plans,code,'.$plan->id],
                'billing_cycle' => ['sometimes', 'in:monthly,annual'],
                'price' => ['sometimes', 'numeric', 'min:0'],
                'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
                'trial_days' => ['sometimes', 'integer', 'min:0'],
                'max_students' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'max_staff' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'features' => ['sometimes', 'nullable', 'array'],
                'features.*' => ['string', 'max:80'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            if (isset($data['code'])) {
                $data['code'] = Str::slug($data['code'], '_');
            }
            if (isset($data['currency'])) {
                $data['currency'] = strtoupper($data['currency']);
            }

            $before = $plan->only(array_keys($data));
            $plan->fill($data);
            $plan->save();

            $this->audit($request, 'plan.updated', 'subscription_plan', $plan->id, [
                'before' => $before,
                'after' => $plan->only(array_keys($data)),
            ]);

            $plan->loadCount([
                'subscriptions as subscriptions_count',
                'subscriptions as active_subscriptions_count' => function ($q) {
                    $q->whereIn('status', ['active', 'trial']);
                },
                'subscriptions as trial_subscriptions_count' => function ($q) {
                    $q->where('status', 'trial');
                },
            ]);

            return $this->success([
                'plan' => $this->serializePlan($plan),
            ], 'Plan updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function plansDestroy(Request $request, int $id)
    {
        try {
            $plan = SubscriptionPlan::query()->findOrFail($id);
            $activeSubs = SchoolSubscription::query()
                ->where('plan_id', $plan->id)
                ->whereIn('status', ['active', 'trial'])
                ->count();

            if ($activeSubs > 0) {
                // Soft-retire: keep history, hide from new sales
                $plan->is_active = false;
                $plan->save();
                $this->audit($request, 'plan.deactivated', 'subscription_plan', $plan->id, [
                    'active_subscriptions' => $activeSubs,
                ]);

                return $this->success([
                    'plan' => $this->serializePlan($plan->fresh()),
                    'deactivated' => true,
                ], 'Plan has active subscriptions and was deactivated instead of deleted');
            }

            $planId = $plan->id;
            $plan->delete();
            $this->audit($request, 'plan.deleted', 'subscription_plan', $planId);

            return $this->success(['deleted' => true], 'Plan deleted');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    protected function serializePlan(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'code' => $plan->code,
            'billing_cycle' => $plan->billing_cycle,
            'price' => (float) $plan->price,
            'currency' => $plan->currency ?? 'USD',
            'trial_days' => (int) $plan->trial_days,
            'max_students' => $plan->max_students,
            'max_staff' => $plan->max_staff,
            'features' => $plan->features ?? [],
            'is_active' => (bool) $plan->is_active,
            'subscriptions_count' => (int) ($plan->subscriptions_count ?? 0),
            'active_subscriptions_count' => (int) ($plan->active_subscriptions_count ?? 0),
            'trial_subscriptions_count' => (int) ($plan->trial_subscriptions_count ?? 0),
            'created_at' => $plan->created_at,
            'updated_at' => $plan->updated_at,
        ];
    }

    public function subscriptionsIndex(Request $request)
    {
        try {
            $query = SchoolSubscription::query()
                ->with(['school:id,name,code,status', 'plan'])
                ->orderByDesc('id');

            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'subscriptions' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function subscriptionsUpdate(Request $request, int $id)
    {
        try {
            $sub = SchoolSubscription::query()->findOrFail($id);
            $data = $request->validate([
                'status' => ['sometimes', 'in:trial,active,past_due,cancelled,expired'],
                'plan_id' => ['sometimes', 'integer', 'exists:subscription_plans,id'],
                'ends_on' => ['sometimes', 'nullable', 'date'],
                'auto_renew' => ['sometimes', 'boolean'],
            ]);

            $sub->fill($data);
            $sub->save();

            $this->audit($request, 'subscription.updated', 'school_subscription', $sub->id, $data);

            return $this->success([
                'subscription' => $sub->fresh()->load(['school', 'plan']),
            ], 'Subscription updated');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Users & Roles ─────────────────────────────────────────────

    public function usersIndex(Request $request)
    {
        try {
            $q = $request->string('q')->toString();
            $query = User::query()
                ->with(['role:id,name,label', 'school:id,name,code'])
                ->orderByDesc('id');

            if ($q !== '') {
                $query->where(function ($b) use ($q) {
                    $b->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            }
            if ($request->boolean('platform_only')) {
                $query->whereNull('school_id');
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'users' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function usersStore(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
                'role_id' => ['required', 'integer', 'exists:roles,id'],
                'school_id' => ['nullable', 'integer', 'exists:schools,id'],
                'phone' => ['nullable', 'string', 'max:40'],
            ]);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => $data['role_id'],
                'school_id' => $data['school_id'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $this->audit($request, 'user.created', 'user', $user->id);

            return $this->success([
                'user' => $user->load(['role', 'school']),
            ], 'User created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function rolesIndex()
    {
        try {
            $roles = Role::query()
                ->orderBy('id')
                ->get()
                ->map(function (Role $role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'label' => $role->label,
                        'users_count' => User::query()->where('role_id', $role->id)->count(),
                    ];
                });

            return $this->success(['roles' => $roles]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Billing ───────────────────────────────────────────────────

    public function billingIndex(Request $request)
    {
        try {
            $query = PlatformInvoice::query()
                ->with(['school:id,name,code', 'subscription.plan'])
                ->orderByDesc('id');

            if ($request->string('status')->toString() !== '') {
                $query->where('status', $request->string('status')->toString());
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

            return $this->success([
                'invoices' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'total' => $page->total(),
                ],
                'summary' => [
                    'paid' => (float) PlatformInvoice::query()->where('status', 'paid')->sum('amount'),
                    'unpaid' => (float) PlatformInvoice::query()->where('status', 'unpaid')->sum('amount'),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function billingStore(Request $request)
    {
        try {
            $data = $request->validate([
                'school_id' => ['required', 'integer', 'exists:schools,id'],
                'subscription_id' => ['nullable', 'integer', 'exists:school_subscriptions,id'],
                'amount' => ['required', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'size:3'],
                'due_date' => ['nullable', 'date'],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

            $invoice = PlatformInvoice::query()->create([
                'school_id' => $data['school_id'],
                'subscription_id' => $data['subscription_id'] ?? null,
                'reference' => 'PLF-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'USD',
                'status' => 'unpaid',
                'issue_date' => now()->toDateString(),
                'due_date' => $data['due_date'] ?? now()->addDays(14)->toDateString(),
                'description' => $data['description'] ?? 'Platform subscription invoice',
            ]);

            $this->audit($request, 'invoice.created', 'platform_invoice', $invoice->id);

            return $this->success([
                'invoice' => $invoice->load('school'),
            ], 'Invoice created', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function billingMarkPaid(Request $request, int $id)
    {
        try {
            $invoice = PlatformInvoice::query()->findOrFail($id);
            $invoice->status = 'paid';
            $invoice->paid_at = now();
            $invoice->save();

            $this->audit($request, 'invoice.paid', 'platform_invoice', $invoice->id);

            return $this->success(['invoice' => $invoice->fresh()], 'Invoice marked paid');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Notifications ─────────────────────────────────────────────

    public function notificationsIndex()
    {
        try {
            return $this->success([
                'notifications' => PlatformNotification::query()
                    ->with('school:id,name,code')
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function notificationsStore(Request $request)
    {
        try {
            $data = $request->validate([
                'title' => ['required', 'string', 'max:200'],
                'body' => ['nullable', 'string'],
                'channel' => ['nullable', 'in:system,email,sms'],
                'audience' => ['nullable', 'in:all,super_admin,school'],
                'school_id' => ['nullable', 'integer', 'exists:schools,id'],
                'status' => ['nullable', 'in:draft,sent,scheduled'],
            ]);

            $status = $data['status'] ?? 'sent';
            $notification = PlatformNotification::query()->create([
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'channel' => $data['channel'] ?? 'system',
                'audience' => $data['audience'] ?? 'all',
                'school_id' => $data['school_id'] ?? null,
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);

            $this->audit($request, 'notification.created', 'platform_notification', $notification->id);

            return $this->success(['notification' => $notification], 'Notification saved', 201);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Settings ──────────────────────────────────────────────────

    public function settingsShow()
    {
        try {
            return $this->success([
                'settings' => [
                    'app_name' => 'Shengena',
                    'support_email' => 'support@shengena.app',
                    'default_currency' => 'USD',
                    'default_trial_days' => 14,
                    'allow_self_registration' => true,
                    'require_school_approval' => true,
                    'auth' => [
                        'jwt_ttl_minutes' => config('jwt.ttl', 60),
                        'password_min_length' => 8,
                    ],
                    'notifications' => [
                        'email_enabled' => true,
                        'sms_enabled' => false,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ── Audit logs ────────────────────────────────────────────────

    public function auditIndex(Request $request)
    {
        try {
            $query = AuditLog::query()
                ->with(['user:id,name,email', 'school:id,name,code'])
                ->orderByDesc('id');

            if ($request->string('action')->toString() !== '') {
                $query->where('action', 'like', '%'.$request->string('action')->toString().'%');
            }

            $page = $query->paginate(min(100, max(1, $request->integer('per_page', 40))));

            return $this->success([
                'logs' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'total' => $page->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    protected function audit(Request $request, string $action, ?string $entityType = null, ?int $entityId = null, mixed $changes = null): void
    {
        try {
            AuditLog::query()->create([
                'school_id' => null,
                'user_id' => $request->user()?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'changes' => $changes,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (Throwable) {
            // never break primary flow
        }
    }
}
