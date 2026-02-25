<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type', 30)->default('cash');
            $table->string('currency', 3)->default('TZS');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('finance_transaction_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type', 20);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('account_id')->constrained('finance_accounts');
            $table->foreignId('category_id')->nullable()->constrained('finance_transaction_categories');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->foreignId('staff_id')->nullable()->constrained('staff');
            $table->decimal('amount', 12, 2);
            $table->string('direction', 10);
            $table->date('occurred_on')->nullable();
            $table->string('reference')->nullable();
            $table->string('description')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'occurred_on']);
            $table->index(['school_id', 'category_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'year', 'month']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('staff_id')->constrained('staff');
            $table->foreignId('salary_level_id')->nullable()->constrained('salary_levels');
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deductions_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['payroll_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_transaction_categories');
        Schema::dropIfExists('finance_accounts');
    }
};

