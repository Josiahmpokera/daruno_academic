<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->date('date');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->foreignId('staff_id')->nullable()->constrained('users');
            $table->string('type', 20);
            $table->string('status', 20);
            $table->time('recorded_at')->nullable();
            $table->string('source', 50)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};

