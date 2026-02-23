<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('admission_number')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'admission_number']);
            $table->index(['school_id', 'last_name']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('classroom_id')->constrained('classrooms');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->date('enrolled_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(
                ['school_id', 'student_id', 'classroom_id', 'academic_year_id', 'term_id'],
                'enrollments_unique'
            );
        });

        Schema::create('parent_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('parent_id')->constrained('users');
            $table->foreignId('student_id')->constrained('students');
            $table->string('relationship', 50)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'parent_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('students');
    }
};

