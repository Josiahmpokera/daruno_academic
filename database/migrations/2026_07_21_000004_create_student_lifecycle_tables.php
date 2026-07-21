<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_transfers')) {
            Schema::create('student_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                // outgoing = leaving this school; incoming = joining this school
                $table->string('direction', 20)->default('outgoing');
                $table->string('other_school_name')->nullable();
                $table->string('other_school_code', 80)->nullable();
                $table->date('transfer_date')->nullable();
                $table->string('reason')->nullable();
                $table->string('reference_number', 80)->nullable();
                // pending | approved | completed | cancelled
                $table->string('status', 30)->default('pending');
                $table->text('notes')->nullable();
                $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['school_id', 'student_id']);
            });
        }

        if (! Schema::hasTable('student_graduations')) {
            Schema::create('student_graduations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->date('graduation_date')->nullable();
                $table->string('certificate_number', 80)->nullable();
                $table->string('level_completed', 80)->nullable(); // e.g. Primary, O-Level
                $table->text('remarks')->nullable();
                $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['school_id', 'student_id']);
                $table->index(['school_id', 'graduation_date']);
            });
        }

        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'alumni_year')) {
                $table->unsignedSmallInteger('alumni_year')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('students', 'alumni_phone')) {
                $table->string('alumni_phone', 40)->nullable()->after('alumni_year');
            }
            if (! Schema::hasColumn('students', 'alumni_email')) {
                $table->string('alumni_email')->nullable()->after('alumni_phone');
            }
            if (! Schema::hasColumn('students', 'alumni_occupation')) {
                $table->string('alumni_occupation', 120)->nullable()->after('alumni_email');
            }
            if (! Schema::hasColumn('students', 'alumni_notes')) {
                $table->text('alumni_notes')->nullable()->after('alumni_occupation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            foreach (['alumni_year', 'alumni_phone', 'alumni_email', 'alumni_occupation', 'alumni_notes'] as $col) {
                if (Schema::hasColumn('students', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('student_graduations');
        Schema::dropIfExists('student_transfers');
    }
};
