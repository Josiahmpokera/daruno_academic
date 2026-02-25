<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('classroom_id')->constrained('classrooms');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'classroom_id', 'name'], 'sections_unique');
        });

        Schema::create('school_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years');
            $table->foreignId('term_id')->nullable()->constrained('terms');
            $table->string('title');
            $table->string('type', 30)->nullable(); // holiday, event, exam, etc.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_calendar_events');
        Schema::dropIfExists('sections');
    }
};

