<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\EducationStages;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'code' => 'starter_monthly',
                'billing_cycle' => 'monthly',
                'price' => 49,
                'trial_days' => 14,
                'max_students' => 200,
                'max_staff' => 20,
                'features' => ['students', 'attendance', 'fees', 'basic_reports'],
            ],
            [
                'name' => 'Basic',
                'code' => 'basic_monthly',
                'billing_cycle' => 'monthly',
                'price' => 89,
                'trial_days' => 14,
                'max_students' => 500,
                'max_staff' => 40,
                'features' => ['students', 'attendance', 'fees', 'exams', 'reports'],
            ],
            [
                'name' => 'Professional',
                'code' => 'professional_monthly',
                'billing_cycle' => 'monthly',
                'price' => 149,
                'trial_days' => 14,
                'max_students' => 1500,
                'max_staff' => 100,
                'features' => ['students', 'attendance', 'fees', 'exams', 'reports', 'library', 'transport'],
            ],
            [
                'name' => 'Growth',
                'code' => 'growth_monthly',
                'billing_cycle' => 'monthly',
                'price' => 129,
                'trial_days' => 14,
                'max_students' => 1000,
                'max_staff' => 80,
                'features' => ['students', 'attendance', 'fees', 'exams', 'reports'],
            ],
            [
                'name' => 'Enterprise',
                'code' => 'enterprise_annual',
                'billing_cycle' => 'annual',
                'price' => 1499,
                'trial_days' => 30,
                'max_students' => null,
                'max_staff' => null,
                'features' => ['all_modules', 'compliance', 'priority_support', 'sso', 'api_access'],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::query()->updateOrCreate(
                ['code' => $plan['code']],
                [...$plan, 'currency' => 'USD', 'is_active' => true]
            );
        }

        $superRole = Role::query()->where('name', 'super_admin')->first();
        if ($superRole) {
            User::query()->updateOrCreate(
                ['email' => 'owner@daruno.app'],
                [
                    'school_id' => null,
                    'role_id' => $superRole->id,
                    'name' => 'Shengena Super Admin',
                    'password' => Hash::make('Daruno123!'),
                ]
            );
        }

        $schoolAdminRole = Role::query()->where('name', 'school_admin')->first()
            ?? Role::query()->where('name', 'school_owner')->first();

        $demoSchools = [
            [
                'name' => 'Green Valley Secondary',
                'code' => 'GVS001',
                'status' => 'active',
                'contact_email' => 'admin@greenvalley.school',
                'plan' => 'growth_monthly',
                'sub_status' => 'active',
                // O-Level + A-Level only
                'education_stages' => [
                    EducationStages::ORDINARY_LEVEL,
                    EducationStages::ADVANCED_LEVEL,
                ],
                'school_type' => 'secondary',
                'region' => 'Kilimanjaro',
            ],
            [
                'name' => 'Sunrise Primary Academy',
                'code' => 'SPA002',
                'status' => 'active',
                'contact_email' => 'hello@sunrise.academy',
                'plan' => 'starter_monthly',
                'sub_status' => 'trial',
                // Pre-primary + Primary only
                'education_stages' => [
                    EducationStages::PRE_PRIMARY,
                    EducationStages::PRIMARY,
                ],
                'school_type' => 'primary',
                'region' => 'Arusha',
            ],
            [
                'name' => 'Lakeview Combined School',
                'code' => 'LHS003',
                'status' => 'active',
                'contact_email' => 'office@lakeview.hs',
                'plan' => 'professional_monthly',
                'sub_status' => 'active',
                // All TAMISEMI stages — full demo
                'education_stages' => EducationStages::keys(),
                'school_type' => 'combined',
                'region' => 'Dar es Salaam',
            ],
        ];

        foreach ($demoSchools as $row) {
            $stages = EducationStages::normalize($row['education_stages'] ?? []);
            $school = School::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'slug' => Str::slug($row['name']),
                    'status' => $row['status'],
                    'contact_email' => $row['contact_email'],
                    'contact_phone' => '+255700000000',
                    'school_type' => $row['school_type'] ?? null,
                    'region' => $row['region'] ?? null,
                    'education_stages' => $stages,
                    'education_level' => EducationStages::labelSummary($stages),
                    'settings' => ['seeded' => true],
                ]
            );

            $plan = SubscriptionPlan::query()->where('code', $row['plan'])->first();
            if ($plan && ! SchoolSubscription::query()->where('school_id', $school->id)->exists()) {
                $sub = SchoolSubscription::query()->create([
                    'school_id' => $school->id,
                    'plan_id' => $plan->id,
                    'status' => $row['sub_status'],
                    'starts_on' => now()->subDays(10)->toDateString(),
                    'trial_ends_on' => now()->addDays(4)->toDateString(),
                    'ends_on' => $row['sub_status'] === 'active'
                        ? now()->addMonth()->toDateString()
                        : now()->addDays(4)->toDateString(),
                    'auto_renew' => true,
                ]);

                PlatformInvoice::query()->firstOrCreate(
                    ['reference' => 'PLF-SEED-'.$school->code],
                    [
                        'school_id' => $school->id,
                        'subscription_id' => $sub->id,
                        'amount' => $plan->price,
                        'currency' => $plan->currency,
                        'status' => $row['sub_status'] === 'active' ? 'paid' : 'unpaid',
                        'issue_date' => now()->subDays(5)->toDateString(),
                        'due_date' => now()->addDays(9)->toDateString(),
                        'paid_at' => $row['sub_status'] === 'active' ? now()->subDays(3) : null,
                        'description' => $plan->name.' plan',
                    ]
                );
            }

            if ($schoolAdminRole) {
                User::query()->updateOrCreate(
                    ['email' => 'admin@'.Str::lower($school->code).'.school'],
                    [
                        'school_id' => $school->id,
                        'role_id' => $schoolAdminRole->id,
                        'name' => $school->name.' Admin',
                        'password' => Hash::make('School123!'),
                    ]
                );
            }
        }

        if (PlatformNotification::query()->count() === 0) {
            PlatformNotification::query()->insert([
                [
                    'channel' => 'system',
                    'title' => 'Welcome to Shengena Platform',
                    'body' => 'Super Admin console is ready. Manage tenants, billing, and subscriptions here.',
                    'audience' => 'super_admin',
                    'school_id' => null,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'channel' => 'email',
                    'title' => 'Trial expiry reminder template',
                    'body' => 'Schools on trial will receive expiry reminders 3 days before end date.',
                    'audience' => 'all',
                    'school_id' => null,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        $owner = User::query()->where('email', 'owner@daruno.app')->first();
        if ($owner && AuditLog::query()->count() === 0) {
            AuditLog::query()->insert([
                [
                    'school_id' => null,
                    'user_id' => $owner->id,
                    'action' => 'platform.seeded',
                    'entity_type' => 'system',
                    'entity_id' => null,
                    'changes' => json_encode(['message' => 'Initial platform seed completed']),
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'PlatformSeeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'school_id' => null,
                    'user_id' => $owner->id,
                    'action' => 'auth.login',
                    'entity_type' => 'user',
                    'entity_id' => $owner->id,
                    'changes' => json_encode(['email' => $owner->email]),
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'PlatformSeeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
