<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years');
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms');
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('applied_at')->nullable();
            $table->date('decided_at')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });

        Schema::create('admission_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained('admissions');
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_guardians');
        Schema::dropIfExists('admissions');
    }
};

