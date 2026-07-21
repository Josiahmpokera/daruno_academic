<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            if (! Schema::hasColumn('admissions', 'application_number')) {
                $table->string('application_number', 40)->nullable()->after('id');
            }
            if (! Schema::hasColumn('admissions', 'middle_name')) {
                $table->string('middle_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('admissions', 'email')) {
                $table->string('email')->nullable()->after('gender');
            }
            if (! Schema::hasColumn('admissions', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }
            if (! Schema::hasColumn('admissions', 'nationality')) {
                $table->string('nationality', 80)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('admissions', 'religion')) {
                $table->string('religion', 80)->nullable()->after('nationality');
            }
            if (! Schema::hasColumn('admissions', 'previous_school')) {
                $table->string('previous_school')->nullable()->after('religion');
            }
            if (! Schema::hasColumn('admissions', 'previous_class')) {
                $table->string('previous_class', 80)->nullable()->after('previous_school');
            }
            if (! Schema::hasColumn('admissions', 'preferred_level')) {
                $table->string('preferred_level', 80)->nullable()->after('previous_class');
            }
            if (! Schema::hasColumn('admissions', 'address')) {
                $table->string('address')->nullable()->after('preferred_level');
            }
            if (! Schema::hasColumn('admissions', 'source')) {
                $table->string('source', 40)->default('school_portal')->after('address');
            }
            if (! Schema::hasColumn('admissions', 'notes')) {
                $table->text('notes')->nullable()->after('source');
            }
            if (! Schema::hasColumn('admissions', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('admissions', 'birth_certificate_no')) {
                $table->string('birth_certificate_no', 80)->nullable()->after('date_of_birth');
            }
        });

        // Unique application number per school when set
        Schema::table('admissions', function (Blueprint $table) {
            try {
                $table->unique(['school_id', 'application_number'], 'admissions_school_app_no_unique');
            } catch (\Throwable) {
                // index may already exist
            }
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            try {
                $table->dropUnique('admissions_school_app_no_unique');
            } catch (\Throwable) {
            }
            $cols = [
                'application_number', 'middle_name', 'email', 'phone', 'nationality',
                'religion', 'previous_school', 'previous_class', 'preferred_level',
                'address', 'source', 'notes', 'rejection_reason', 'birth_certificate_no',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('admissions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
