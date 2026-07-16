<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('billing_cycle', 20)->default('monthly'); // monthly|annual
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('trial_days')->default(14);
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('school_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->string('status', 30)->default('trial'); // trial|active|past_due|cancelled|expired
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('trial_ends_on')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('school_subscriptions')->nullOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('unpaid'); // unpaid|paid|failed|refunded
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });

        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 30)->default('system'); // system|email|sms
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('audience', 30)->default('all'); // all|super_admin|school
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft|sent|scheduled
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('schools', function (Blueprint $table) {
            if (! Schema::hasColumn('schools', 'status')) {
                $table->string('status', 30)->default('pending')->after('slug');
            }
            if (! Schema::hasColumn('schools', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('status');
            }
            if (! Schema::hasColumn('schools', 'contact_phone')) {
                $table->string('contact_phone')->nullable()->after('contact_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            foreach (['status', 'contact_email', 'contact_phone'] as $col) {
                if (Schema::hasColumn('schools', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('platform_notifications');
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('school_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
