<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms');
            $table->foreignId('subject_id')->nullable()->constrained('subjects');
            $table->foreignId('teacher_id')->nullable()->constrained('staff');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses');
            $table->string('title');
            $table->text('content')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('lesson_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons');
            $table->string('type', 30)->nullable(); // file, video, link
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms');
            $table->foreignId('subject_id')->nullable()->constrained('subjects');
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->foreignId('teacher_id')->nullable()->constrained('staff');
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->decimal('max_score', 5, 2)->default(100);
            $table->string('type', 30)->default('homework'); // homework, quiz, project
            $table->timestamps();
            $table->index(['school_id', 'classroom_id', 'due_at']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments');
            $table->foreignId('student_id')->constrained('students');
            $table->dateTime('submitted_at')->nullable();
            $table->text('content')->nullable();
            $table->string('attachment_path')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('lesson_attachments');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('courses');
    }
};

