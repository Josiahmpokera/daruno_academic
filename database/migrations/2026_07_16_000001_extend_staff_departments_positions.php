<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('staff_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::table('staff', function (Blueprint $table) {
            if (! Schema::hasColumn('staff', 'employee_code')) {
                $table->string('employee_code', 50)->nullable()->after('school_id');
            }
            if (! Schema::hasColumn('staff', 'first_name')) {
                $table->string('first_name')->nullable()->after('employee_code');
            }
            if (! Schema::hasColumn('staff', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('staff', 'email')) {
                $table->string('email')->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('staff', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable()->after('email');
            }
            if (! Schema::hasColumn('staff', 'staff_position_id')) {
                $table->unsignedBigInteger('staff_position_id')->nullable()->after('department_id');
            }
        });

        // Foreign keys for new relations (ignore if already present)
        try {
            Schema::table('staff', function (Blueprint $table) {
                $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
                $table->foreign('staff_position_id')->references('id')->on('staff_positions')->nullOnDelete();
            });
        } catch (\Throwable) {
            // already exists
        }

        $this->makeUserIdNullable();

        try {
            Schema::table('staff', function (Blueprint $table) {
                $table->unique(['school_id', 'employee_code']);
            });
        } catch (\Throwable) {
            // ignore if index exists or null uniqueness issues
        }
    }

    protected function makeUserIdNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // Rebuild staff table with nullable user_id
            Schema::getConnection()->statement('PRAGMA foreign_keys=OFF');

            Schema::create('staff_tmp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools');
                $table->string('employee_code', 50)->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->unsignedBigInteger('staff_position_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('salary_level_id')->nullable();
                $table->string('position')->nullable();
                $table->string('employment_type', 50)->nullable();
                $table->date('hire_date')->nullable();
                $table->date('contract_end_date')->nullable();
                $table->string('status', 20)->default('active');
                $table->string('national_id')->nullable();
                $table->string('phone')->nullable();
                $table->timestamps();
            });

            $cols = 'id, school_id, employee_code, first_name, last_name, email, department_id, staff_position_id, user_id, salary_level_id, position, employment_type, hire_date, contract_end_date, status, national_id, phone, created_at, updated_at';
            // old table may not have new cols — select existing only
            $existing = Schema::getColumnListing('staff');
            $select = collect([
                'id', 'school_id', 'user_id', 'salary_level_id', 'position',
                'employment_type', 'hire_date', 'contract_end_date', 'status',
                'national_id', 'phone', 'created_at', 'updated_at',
                'employee_code', 'first_name', 'last_name', 'email',
                'department_id', 'staff_position_id',
            ])->filter(fn ($c) => in_array($c, $existing, true))->values();

            $from = $select->implode(', ');
            $into = $select->implode(', ');
            DB::statement("INSERT INTO staff_tmp ({$into}) SELECT {$from} FROM staff");

            Schema::drop('staff');
            Schema::rename('staff_tmp', 'staff');

            Schema::table('staff', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('salary_level_id')->references('id')->on('salary_levels')->nullOnDelete();
                $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
                $table->foreign('staff_position_id')->references('id')->on('staff_positions')->nullOnDelete();
                $table->unique(['school_id', 'user_id']);
            });

            Schema::getConnection()->statement('PRAGMA foreign_keys=ON');

            return;
        }

        // MySQL / MariaDB / Postgres
        try {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Throwable) {
        }

        DB::statement('ALTER TABLE staff MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::table('staff', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            try {
                $table->dropUnique(['school_id', 'employee_code']);
            } catch (\Throwable) {
            }
            try {
                $table->dropForeign(['department_id']);
                $table->dropForeign(['staff_position_id']);
            } catch (\Throwable) {
            }
            foreach (['employee_code', 'first_name', 'last_name', 'email', 'department_id', 'staff_position_id'] as $col) {
                if (Schema::hasColumn('staff', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('staff_positions');
        Schema::dropIfExists('departments');
    }
};
