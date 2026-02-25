<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_core')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms');
            $table->foreignId('subject_id')->nullable()->constrained('subjects');
            $table->string('name');
            $table->string('type', 30);
            $table->date('date')->nullable();
            $table->decimal('max_score', 5, 2)->default(100);
            $table->decimal('weight', 5, 2)->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->index(['school_id', 'academic_year_id', 'term_id']);
        });

        Schema::create('assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments');
            $table->foreignId('student_id')->constrained('students');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->string('remark')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'student_id']);
        });

        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->foreignId('classroom_id')->constrained('classrooms');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'academic_year_id', 'term_id', 'classroom_id'], 'timetables_unique');
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_id')->constrained('timetables');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('subject_id')->nullable()->constrained('subjects');
            $table->foreignId('staff_id')->nullable()->constrained('staff');
            $table->string('room')->nullable();
            $table->timestamps();
            $table->index(['timetable_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('timetables');
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('subjects');
    }
};

