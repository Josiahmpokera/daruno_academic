<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_compulsory')->default(true);
            $table->boolean('is_recurring')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms');
            $table->foreignId('fee_category_id')->constrained('fee_categories');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('TZS');
            $table->date('due_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(
                ['school_id', 'academic_year_id', 'term_id', 'classroom_id', 'fee_category_id'],
                'fee_structures_unique'
            );
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->string('reference')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('balance_amount', 10, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'reference']);
            $table->index(['school_id', 'student_id']);
        });

        Schema::create('fee_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('fee_invoices');
            $table->foreignId('fee_category_id')->nullable()->constrained('fee_categories');
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('invoice_id')->nullable()->constrained('fee_invoices');
            $table->decimal('amount', 10, 2);
            $table->string('method', 50)->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('completed');
            $table->timestamps();
            $table->index(['school_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_invoice_items');
        Schema::dropIfExists('fee_invoices');
        Schema::dropIfExists('fee_structures');
        Schema::dropIfExists('fee_categories');
    }
};

