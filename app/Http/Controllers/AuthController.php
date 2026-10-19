<?php

namespace App\Http\Controllers;

use App\Models\PlatformInvoice;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\EducationStages;
use App\Support\TenantAccess;
use Database\Seeders\TamisemiSubjectsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // Password is hashed by the User model cast — do not Hash::make here.
            'password' => $data['password'],
        ]);

        $token = auth()->login($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * Public typeahead: search schools already in the platform registry
     * (e.g. Tanzania bulk import) so a school can claim its record on register.
     */
    public function searchRegistrySchools(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $q = trim($data['q']);
        $limit = (int) ($data['limit'] ?? 15);

        // Schools that already have a portal user are "claimed"
        $claimedIds = User::query()
            ->whereNotNull('school_id')
            ->distinct()
            ->pluck('school_id')
            ->all();

        $schools = School::query()
            ->where(function ($b) use ($q) {
                $b->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('registration_number', 'like', "%{$q}%")
                    ->orWhere('emis_code', 'like', "%{$q}%")
                    ->orWhere('region', 'like', "%{$q}%")
                    ->orWhere('district', 'like', "%{$q}%");
            })
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
            ->orderBy('name')
            ->limit($limit)
            ->get([
                'id', 'name', 'code', 'registration_number', 'emis_code',
                'region', 'district', 'ownership', 'education_level',
                'education_stages', 'status', 'settings',
            ]);

        $items = $schools->map(function (School $s) use ($claimedIds) {
            $claimed = in_array($s->id, $claimedIds, true);
            $source = is_array($s->settings) ? ($s->settings['source'] ?? null) : null;

            return [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'registration_number' => $s->registration_number,
                'emis_code' => $s->emis_code,
                'region' => $s->region,
                'district' => $s->district,
                'ownership' => $s->ownership,
                'education_level' => $s->education_level,
                'education_stages' => $s->offeredStages(),
                'status' => $s->status,
                'source' => $source,
                'is_claimed' => $claimed,
                'available' => ! $claimed,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'error' => '',
            'data' => [
                'schools' => $items,
                'meta' => [
                    'q' => $q,
                    'count' => $items->count(),
                ],
            ],
        ]);
    }

    public function registerSchool(Request $request)
    {
        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'school_code' => ['nullable', 'string', 'max:50'],
            // Optional: claim an existing registry / directory school
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
            // Multi-select TAMISEMI stages the school offers
            'education_stages' => ['required', 'array', 'min:1'],
            'education_stages.*' => ['required', 'string', Rule::in(EducationStages::keys())],
            // trial | paid — landing page drives this
            'registration_mode' => ['nullable', 'in:trial,paid'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $stages = EducationStages::normalize($data['education_stages']);
        if ($stages === []) {
            return response()->json([
                'success' => false,
                'error' => 'Select at least one education level (e.g. Primary, O-Level).',
                'data' => (object) [],
            ], 422);
        }

        $mode = $data['registration_mode'] ?? 'trial';
        $plan = null;
        if (! empty($data['plan_id'])) {
            $plan = SubscriptionPlan::query()
                ->where('is_active', true)
                ->find($data['plan_id']);
            if (! $plan) {
                return response()->json([
                    'success' => false,
                    'error' => 'Selected plan is not available.',
                    'data' => (object) [],
                ], 422);
            }
        } elseif ($mode === 'paid') {
            return response()->json([
                'success' => false,
                'error' => 'Please select a subscription package to continue with paid registration.',
                'data' => (object) [],
            ], 422);
        } else {
            // Trial: attach cheapest active plan for limits/features
            $plan = SubscriptionPlan::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->orderBy('id')
                ->first();
        }

        // Unique code only when creating a new school (not claiming)
        if (empty($data['school_id']) && ! empty($data['school_code'])) {
            if (School::query()->where('code', $data['school_code'])->exists()) {
                return response()->json([
                    'success' => false,
                    'error' => 'That school code is already in use. Choose another or pick your school from the search list.',
                    'data' => (object) [],
                ], 422);
            }
        }

        try {
            return DB::transaction(function () use ($data, $stages, $mode, $plan) {
                $school = null;

                if (! empty($data['school_id'])) {
                    $school = School::query()->findOrFail($data['school_id']);

                    // Already onboarded if any user is linked
                    if (User::query()->where('school_id', $school->id)->exists()) {
                        throw new \InvalidArgumentException(
                            'This school is already registered on Shengena. Sign in with the school admin account instead.'
                        );
                    }

                    $settings = is_array($school->settings) ? $school->settings : [];
                    $settings['claimed_at'] = now()->toIso8601String();
                    $settings['claimed_via'] = 'self_registration';
                    $settings['registration_mode'] = $mode;

                    $school->fill([
                        'name' => $data['school_name'],
                        'education_stages' => $stages,
                        'education_level' => EducationStages::labelSummary($stages),
                        'status' => 'active',
                        'settings' => $settings,
                    ]);
                    // Optional code only if free or already this school
                    if (! empty($data['school_code'])
                        && $data['school_code'] !== $school->code
                        && ! School::query()->where('code', $data['school_code'])->where('id', '!=', $school->id)->exists()) {
                        $school->code = $data['school_code'];
                    }
                    $school->save();
                } else {
                    $code = $data['school_code'] ?? null;

                    if (! $code) {
                        do {
                            $code = strtoupper(Str::random(6));
                        } while (School::query()->where('code', $code)->exists());
                    }

                    $slugBase = Str::slug($data['school_name']);
                    $slug = $slugBase ?: Str::lower($code);

                    if (School::query()->where('slug', $slug)->exists()) {
                        $slug = $slug.'-'.Str::random(4);
                    }

                    $school = School::query()->create([
                        'name' => $data['school_name'],
                        'code' => $code,
                        'slug' => $slug,
                        'status' => 'active',
                        'education_stages' => $stages,
                        'education_level' => EducationStages::labelSummary($stages),
                        'settings' => [
                            'source' => 'self_registration',
                            'claimed_at' => now()->toIso8601String(),
                            'registration_mode' => $mode,
                        ],
                    ]);
                }

                $role = Role::query()->where('name', 'school_admin')->firstOrFail();

                $admin = User::query()->create([
                    'school_id' => $school->id,
                    'role_id' => $role->id,
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    // Hashed by User cast
                    'password' => $data['admin_password'],
                ]);

                // Seed curriculum catalogue only for selected stages
                (new TamisemiSubjectsSeeder)->seedSchool((int) $school->id, $stages);

                // Subscription + trial / payment tracking
                $subscriptionPayload = $this->createRegistrationSubscription(
                    $school,
                    $plan,
                    $mode,
                    $data['payment_method'] ?? null,
                    $data['payment_reference'] ?? null,
                );

                $token = auth()->login($admin);

                $response = $this->respondWithToken($token, $admin, $school->fresh());
                $payload = $response->getData(true);
                $payload['subscription'] = $subscriptionPayload['subscription'] ?? null;
                $payload['invoice'] = $subscriptionPayload['invoice'] ?? null;
                $payload['registration_mode'] = $mode;

                return response()->json($payload, 201);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => (object) [],
            ], 422);
        }
    }

    /**
     * Start trial (15 days by default) or paid subscription + platform invoice.
     *
     * @return array{subscription: ?array, invoice: ?array}
     */
    protected function createRegistrationSubscription(
        School $school,
        ?SubscriptionPlan $plan,
        string $mode,
        ?string $paymentMethod,
        ?string $paymentReference,
    ): array {
        if (! $plan) {
            return ['subscription' => null, 'invoice' => null];
        }

        // Avoid duplicate if re-claiming mid-flow (shouldn't happen)
        $existing = SchoolSubscription::query()->where('school_id', $school->id)->first();
        if ($existing) {
            return [
                'subscription' => $this->serializeSubscription($existing->load('plan')),
                'invoice' => null,
            ];
        }

        $settings = SystemSetting::platformAll();
        $trialDays = (int) ($settings['default_trial_days'] ?? 15);
        if ($trialDays < 1) {
            $trialDays = 15;
        }
        // Landing "15 Days Trial" path always uses at least 15 days
        if ($mode === 'trial') {
            $trialDays = max(15, $trialDays);
        }

        $startsOn = now()->toDateString();
        $invoice = null;

        if ($mode === 'trial') {
            $sub = SchoolSubscription::query()->create([
                'school_id' => $school->id,
                'plan_id' => $plan->id,
                'status' => 'trial',
                'starts_on' => $startsOn,
                'ends_on' => now()->addDays($trialDays)->toDateString(),
                'trial_ends_on' => now()->addDays($trialDays)->toDateString(),
                'auto_renew' => false,
            ]);
        } else {
            // Paid package — access until payment confirmed (invoice unpaid)
            $cycleDays = ($plan->billing_cycle === 'annual') ? 365 : 30;
            $sub = SchoolSubscription::query()->create([
                'school_id' => $school->id,
                'plan_id' => $plan->id,
                'status' => 'past_due', // awaiting payment
                'starts_on' => $startsOn,
                'ends_on' => now()->addDays($cycleDays)->toDateString(),
                'trial_ends_on' => null,
                'auto_renew' => true,
            ]);

            $ref = 'PLI-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $desc = 'Subscription: '.$plan->name.' ('.$plan->billing_cycle.')';
            if ($paymentMethod) {
                $desc .= ' · Method: '.$paymentMethod;
            }
            if ($paymentReference) {
                $desc .= ' · Ref: '.$paymentReference;
            }

            $invoice = PlatformInvoice::query()->create([
                'school_id' => $school->id,
                'subscription_id' => $sub->id,
                'reference' => $ref,
                'amount' => $plan->price,
                'currency' => $plan->currency ?? 'TZS',
                'status' => 'unpaid',
                'issue_date' => $startsOn,
                'due_date' => now()->addDays(7)->toDateString(),
                'description' => $desc,
            ]);
        }

        return [
            'subscription' => $this->serializeSubscription($sub->load('plan')),
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'amount' => (float) $invoice->amount,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'due_date' => optional($invoice->due_date)?->toDateString(),
                'description' => $invoice->description,
            ] : null,
        ];
    }

    protected function serializeSubscription(SchoolSubscription $sub): array
    {
        $trialEnds = $sub->trial_ends_on;
        $daysLeft = null;
        if ($sub->status === 'trial' && $trialEnds) {
            $daysLeft = $trialEnds->isPast()
                ? 0
                : (int) now()->startOfDay()->diffInDays($trialEnds->copy()->startOfDay());
        }

        return [
            'id' => $sub->id,
            'status' => $sub->status,
            'starts_on' => optional($sub->starts_on)?->toDateString(),
            'ends_on' => optional($sub->ends_on)?->toDateString(),
            'trial_ends_on' => optional($sub->trial_ends_on)?->toDateString(),
            'trial_days_left' => $daysLeft,
            'auto_renew' => (bool) $sub->auto_renew,
            'plan' => $sub->plan ? [
                'id' => $sub->plan->id,
                'name' => $sub->plan->name,
                'code' => $sub->plan->code,
                'price' => (float) $sub->plan->price,
                'currency' => $sub->plan->currency,
                'billing_cycle' => $sub->plan->billing_cycle,
                'trial_days' => (int) $sub->plan->trial_days,
            ] : null,
        ];
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $token = auth()->attempt($credentials)) {
            try {
                \App\Models\LoginHistory::query()->create([
                    'school_id' => null,
                    'user_id' => null,
                    'email' => $credentials['email'],
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 250),
                    'logged_in_at' => now(),
                ]);
            } catch (\Throwable) {
            }

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = auth()->user();

        try {
            \App\Models\LoginHistory::query()->create([
                'school_id' => $user?->school_id,
                'user_id' => $user?->id,
                'email' => $user?->email,
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 250),
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable) {
        }

        return $this->respondWithToken($token, $user);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'data' => [],
            ], 401);
        }

        $user->loadMissing('role', 'school');

        return response()->json([
            'success' => true,
            'error' => '',
            'data' => [
                'user' => $this->serializeUser($user),
            ],
        ]);
    }

    public function logout()
    {
        auth()->logout();

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    public function refresh()
    {
        $token = auth()->refresh();
        $user = auth()->user();

        return $this->respondWithToken($token, $user);
    }

    protected function respondWithToken(string $token, ?User $user = null, ?School $schoolOverride = null)
    {
        if (! $user) {
            $user = auth()->user();
        }

        $user->loadMissing('role', 'school');

        $serialized = $this->serializeUser($user, $schoolOverride);
        $audience = ($serialized['role']['name'] ?? null) === 'super_admin'
            ? 'platform'
            : 'school';

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'audience' => $audience,
            'user' => $serialized,
        ]);
    }

    /**
     * Canonical user payload for login / me / refresh — never mix tenants.
     */
    protected function serializeUser(User $user, ?School $schoolOverride = null): array
    {
        $user->loadMissing('role', 'school');
        $school = $schoolOverride ?: $user->school;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'school_id' => $user->school_id,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'label' => $user->role->label,
            ] : null,
            'school' => $school ? [
                'id' => $school->id,
                'name' => $school->name,
                'code' => $school->code,
                'slug' => $school->slug,
                'education_stages' => $school->offeredStages(),
                'education_level' => $school->education_level
                    ?: EducationStages::labelSummary($school->offeredStages()),
                'status' => $school->status,
                'subscription' => $this->currentSubscriptionPayload($school),
            ] : null,
            // RBAC area map (config/daruno_access.php) — the portal hides what is false
            'permissions' => TenantAccess::permissionsFor($user),
        ];
    }

    protected function currentSubscriptionPayload(School $school): ?array
    {
        $sub = SchoolSubscription::query()
            ->where('school_id', $school->id)
            ->with('plan')
            ->orderByDesc('id')
            ->first();

        return $sub ? $this->serializeSubscription($sub) : null;
    }
}
