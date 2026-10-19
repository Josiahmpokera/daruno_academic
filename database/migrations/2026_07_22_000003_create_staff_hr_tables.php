<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industrial staff HR: extended profile, contracts, documents, leave, attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff')) {
            Schema::table('staff', function (Blueprint $table) {
                if (! Schema::hasColumn('staff', 'middle_name')) {
                    $table->string('middle_name', 120)->nullable()->after('first_name');
                }
                if (! Schema::hasColumn('staff', 'date_of_birth')) {
                    $table->date('date_of_birth')->nullable()->after('national_id');
                }
                if (! Schema::hasColumn('staff', 'gender')) {
                    $table->string('gender', 20)->nullable()->after('date_of_birth');
                }
                if (! Schema::hasColumn('staff', 'address')) {
                    $table->string('address')->nullable()->after('phone');
                }
                if (! Schema::hasColumn('staff', 'emergency_contact_name')) {
                    $table->string('emergency_contact_name', 160)->nullable()->after('address');
                }
                if (! Schema::hasColumn('staff', 'emergency_contact_phone')) {
                    $table->string('emergency_contact_phone', 40)->nullable()->after('emergency_contact_name');
                }
                if (! Schema::hasColumn('staff', 'bank_name')) {
                    $table->string('bank_name', 120)->nullable()->after('emergency_contact_phone');
                }
                if (! Schema::hasColumn('staff', 'bank_account')) {
                    $table->string('bank_account', 80)->nullable()->after('bank_name');
                }
                if (! Schema::hasColumn('staff', 'bank_branch')) {
                    $table->string('bank_branch', 120)->nullable()->after('bank_account');
                }
                if (! Schema::hasColumn('staff', 'onboarding_status')) {
                    $table->string('onboarding_status', 30)->default('completed')->after('status');
                }
                if (! Schema::hasColumn('staff', 'terminated_at')) {
                    $table->date('terminated_at')->nullable()->after('contract_end_date');
                }
                if (! Schema::hasColumn('staff', 'notes')) {
                    $table->text('notes')->nullable()->after('terminated_at');
                }
            });
        }

        if (! Schema::hasTable('staff_contracts')) {
            Schema::create('staff_contracts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->string('contract_number', 60)->nullable();
                $table->string('title')->nullable();
                $table->string('employment_type', 50)->default('Full-time');
                $table->date('start_date'); // joining / contract start
                $table->date('end_date')->nullable();
                $table->date('probation_end_date')->nullable();
                $table->decimal('basic_salary', 14, 2)->nullable();
                $table->string('currency', 10)->default('TZS');
                $table->string('status', 20)->default('active'); // draft, active, expired, terminated, renewed
                $table->text('terms')->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['school_id', 'staff_id']);
                $table->index(['school_id', 'status']);
            });
        }

        if (! Schema::hasTable('staff_documents')) {
            Schema::create('staff_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->string('doc_type', 40)->default('other');
                $table->string('title');
                $table->string('file_path')->nullable();
                $table->string('external_url')->nullable();
                $table->date('issued_at')->nullable();
                $table->date('expires_at')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['school_id', 'staff_id']);
            });
        }

        if (! Schema::hasTable('staff_leave_requests')) {
            Schema::create('staff_leave_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->string('leave_type', 40)->default('annual');
                $table->date('start_date');
                $table->date('end_date');
                $table->unsignedSmallInteger('days')->default(1);
                $table->text('reason')->nullable();
                $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('rejection_reason')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['school_id', 'staff_id']);
            });
        }

        if (! Schema::hasTable('staff_attendance_records')) {
            Schema::create('staff_attendance_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->date('date');
                $table->string('status', 20)->default('present'); // present, absent, late, half_day, on_leave, holiday
                $table->time('check_in')->nullable();
                $table->time('check_out')->nullable();
                $table->string('notes')->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['school_id', 'staff_id', 'date'], 'staff_attendance_unique');
                $table->index(['school_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_records');
        Schema::dropIfExists('staff_leave_requests');
        Schema::dropIfExists('staff_documents');
        Schema::dropIfExists('staff_contracts');

        if (Schema::hasTable('staff')) {
            Schema::table('staff', function (Blueprint $table) {
                foreach ([
                    'middle_name', 'date_of_birth', 'gender', 'address',
                    'emergency_contact_name', 'emergency_contact_phone',
                    'bank_name', 'bank_account', 'bank_branch',
                    'onboarding_status', 'terminated_at', 'notes',
                ] as $col) {
                    if (Schema::hasColumn('staff', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
