<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->string('name');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'academic_year_id', 'name']);
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
    }
};

