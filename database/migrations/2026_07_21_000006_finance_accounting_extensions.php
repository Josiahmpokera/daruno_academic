<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_payments', 'receipt_number')) {
                $table->string('receipt_number', 50)->nullable()->after('reference');
            }
            if (! Schema::hasColumn('fee_payments', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
            if (! Schema::hasColumn('fee_payments', 'refunded_amount')) {
                $table->decimal('refunded_amount', 12, 2)->default(0)->after('amount');
            }
        });

        if (! Schema::hasTable('fee_refunds')) {
            Schema::create('fee_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained('fee_payments')->nullOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained('fee_invoices')->nullOnDelete();
                $table->string('reference', 50)->nullable();
                $table->decimal('amount', 12, 2);
                $table->string('method', 50)->nullable();
                $table->string('reason')->nullable();
                $table->string('status', 20)->default('completed');
                $table->timestamp('refunded_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'student_id']);
            });
        }

        if (! Schema::hasTable('fee_adjustments')) {
            Schema::create('fee_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained('fee_invoices')->nullOnDelete();
                // discount | fine
                $table->string('type', 20);
                $table->string('reference', 50)->nullable();
                $table->string('title');
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('posted'); // draft|posted|void
                $table->date('effective_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'type', 'status']);
            });
        }

        // Chart of accounts (double-entry)
        if (! Schema::hasTable('chart_of_accounts')) {
            Schema::create('chart_of_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('name');
                // asset | liability | equity | income | expense
                $table->string('type', 20);
                $table->string('subtype', 40)->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'code']);
            });
        }

        if (! Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('coa_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
                $table->string('name');
                $table->string('bank_name')->nullable();
                $table->string('account_number', 80)->nullable();
                $table->string('currency', 3)->default('TZS');
                $table->decimal('opening_balance', 14, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->string('name');
                $table->unsignedSmallInteger('fiscal_year')->nullable();
                $table->string('status', 20)->default('draft'); // draft|active|closed
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('budget_lines')) {
            Schema::create('budget_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
                $table->foreignId('coa_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
                $table->string('label');
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('period', 20)->default('annual'); // annual|term|month
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('entry_number', 50)->nullable();
                $table->date('entry_date');
                $table->string('memo')->nullable();
                $table->string('source', 40)->default('manual'); // manual|fee|payroll|system
                $table->string('status', 20)->default('posted'); // draft|posted|void
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['school_id', 'entry_date']);
            });
        }

        if (! Schema::hasTable('journal_lines')) {
            Schema::create('journal_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
                $table->foreignId('coa_id')->constrained('chart_of_accounts')->cascadeOnDelete();
                $table->decimal('debit', 14, 2)->default(0);
                $table->decimal('credit', 14, 2)->default(0);
                $table->string('description')->nullable();
                $table->timestamps();
                $table->index(['coa_id']);
            });
        }

        // Operational income/expense cash movements (cashbook)
        if (! Schema::hasTable('cash_movements')) {
            Schema::create('cash_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
                $table->foreignId('coa_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
                // income | expense
                $table->string('type', 20);
                $table->string('reference', 50)->nullable();
                $table->string('payee')->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('method', 50)->nullable();
                $table->date('occurred_on');
                $table->string('description')->nullable();
                $table->string('status', 20)->default('posted');
                $table->timestamps();
                $table->index(['school_id', 'type', 'occurred_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('fee_adjustments');
        Schema::dropIfExists('fee_refunds');
        Schema::table('fee_payments', function (Blueprint $table) {
            foreach (['receipt_number', 'notes', 'refunded_amount'] as $col) {
                if (Schema::hasColumn('fee_payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
