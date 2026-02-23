<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->decimal('base_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('salary_level_id')->nullable()->constrained('salary_levels');
            $table->string('position')->nullable();
            $table->string('employment_type', 50)->nullable();
            $table->date('hire_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('national_id')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
        Schema::dropIfExists('salary_levels');
    }
};

