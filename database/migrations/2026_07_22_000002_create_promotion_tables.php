<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAMISEMI-style student promotion rules, runs, and decisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotion_rules')) {
            Schema::create('promotion_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->foreignId('education_level_id')->nullable()->constrained('education_levels')->nullOnDelete();
                $table->foreignId('from_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->foreignId('to_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->decimal('min_average_percent', 5, 2)->default(40);
                $table->decimal('min_attendance_percent', 5, 2)->default(75);
                $table->unsignedTinyInteger('priority')->default(10);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('promotion_runs')) {
            Schema::create('promotion_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('from_academic_year_id')->constrained('academic_years');
                $table->foreignId('to_academic_year_id')->constrained('academic_years');
                $table->foreignId('from_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->string('mode', 20); // automatic, manual, repeat
                $table->string('status', 20)->default('completed'); // draft, completed, cancelled
                $table->string('title')->nullable();
                $table->timestamp('run_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('promoted_count')->default(0);
                $table->unsignedInteger('repeated_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'from_academic_year_id']);
            });
        }

        if (! Schema::hasTable('promotion_decisions')) {
            Schema::create('promotion_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('promotion_run_id')->constrained('promotion_runs')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('from_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->foreignId('to_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->foreignId('from_enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
                $table->foreignId('to_enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
                $table->foreignId('promotion_rule_id')->nullable()->constrained('promotion_rules')->nullOnDelete();
                $table->string('outcome', 20); // promoted, repeated, skipped
                $table->decimal('average_percent', 6, 2)->nullable();
                $table->decimal('attendance_percent', 6, 2)->nullable();
                $table->string('reason')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'promotion_run_id']);
                $table->unique(['promotion_run_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_decisions');
        Schema::dropIfExists('promotion_runs');
        Schema::dropIfExists('promotion_rules');
    }
};
