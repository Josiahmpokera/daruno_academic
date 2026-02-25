<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_health_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->string('blood_group')->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'student_id']);
        });

        Schema::create('clinic_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->date('visit_date')->nullable();
            $table->string('reason')->nullable();
            $table->text('treatment')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'visit_date']);
        });

        Schema::create('discipline_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->date('incident_date')->nullable();
            $table->string('type', 50)->nullable();
            $table->text('description')->nullable();
            $table->text('action_taken')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'incident_date']);
        });

        Schema::create('behavior_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->date('date')->nullable();
            $table->string('behavior_type', 50)->nullable(); // positive, negative
            $table->text('description')->nullable();
            $table->integer('points')->default(0);
            $table->timestamps();
            $table->index(['school_id', 'date']);
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->date('date')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('points')->default(0);
            $table->timestamps();
            $table->index(['school_id', 'date']);
        });

        Schema::create('counseling_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('counselor_id')->nullable()->constrained('staff');
            $table->date('session_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'session_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counseling_sessions');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('behavior_records');
        Schema::dropIfExists('discipline_records');
        Schema::dropIfExists('clinic_visits');
        Schema::dropIfExists('student_health_records');
    }
};

