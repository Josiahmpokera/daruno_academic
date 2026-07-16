<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAMISEMI / PREMS-style student registration fields for school SIS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'middle_name')) {
                $table->string('middle_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('students', 'prems_number')) {
                $table->string('prems_number', 50)->nullable()->after('admission_number');
            }
            if (! Schema::hasColumn('students', 'birth_certificate_no')) {
                $table->string('birth_certificate_no', 80)->nullable()->after('date_of_birth');
            }
            if (! Schema::hasColumn('students', 'place_of_birth')) {
                $table->string('place_of_birth')->nullable()->after('birth_certificate_no');
            }
            if (! Schema::hasColumn('students', 'district_of_birth')) {
                $table->string('district_of_birth', 120)->nullable()->after('place_of_birth');
            }
            if (! Schema::hasColumn('students', 'region_of_birth')) {
                $table->string('region_of_birth', 120)->nullable()->after('district_of_birth');
            }
            if (! Schema::hasColumn('students', 'nationality')) {
                $table->string('nationality', 80)->nullable()->default('Tanzanian')->after('region_of_birth');
            }
            if (! Schema::hasColumn('students', 'religion')) {
                $table->string('religion', 80)->nullable()->after('nationality');
            }
            if (! Schema::hasColumn('students', 'disability')) {
                $table->string('disability', 120)->nullable()->after('religion');
            }
            if (! Schema::hasColumn('students', 'blood_group')) {
                $table->string('blood_group', 10)->nullable()->after('disability');
            }
            if (! Schema::hasColumn('students', 'phone')) {
                $table->string('phone', 40)->nullable()->after('blood_group');
            }
            if (! Schema::hasColumn('students', 'address_region')) {
                $table->string('address_region', 120)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('students', 'address_district')) {
                $table->string('address_district', 120)->nullable()->after('address_region');
            }
            if (! Schema::hasColumn('students', 'address_ward')) {
                $table->string('address_ward', 120)->nullable()->after('address_district');
            }
            if (! Schema::hasColumn('students', 'address_street')) {
                $table->string('address_street')->nullable()->after('address_ward');
            }
            if (! Schema::hasColumn('students', 'previous_school')) {
                $table->string('previous_school')->nullable()->after('address_street');
            }
            if (! Schema::hasColumn('students', 'previous_class')) {
                $table->string('previous_class', 80)->nullable()->after('previous_school');
            }
            if (! Schema::hasColumn('students', 'boarding_status')) {
                $table->string('boarding_status', 30)->nullable()->default('day')->after('previous_class');
            }
            if (! Schema::hasColumn('students', 'admission_date')) {
                $table->date('admission_date')->nullable()->after('boarding_status');
            }
            if (! Schema::hasColumn('students', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });

        if (! Schema::hasTable('student_guardians')) {
            Schema::create('student_guardians', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('relationship', 40); // father, mother, guardian
                $table->string('full_name');
                $table->string('phone', 40)->nullable();
                $table->string('email')->nullable();
                $table->string('occupation')->nullable();
                $table->string('nida_number', 50)->nullable();
                $table->string('address')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->index(['school_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');

        Schema::table('students', function (Blueprint $table) {
            $cols = [
                'middle_name', 'prems_number', 'birth_certificate_no', 'place_of_birth',
                'district_of_birth', 'region_of_birth', 'nationality', 'religion',
                'disability', 'blood_group', 'phone', 'address_region', 'address_district',
                'address_ward', 'address_street', 'previous_school', 'previous_class',
                'boarding_status', 'admission_date', 'notes',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('students', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
