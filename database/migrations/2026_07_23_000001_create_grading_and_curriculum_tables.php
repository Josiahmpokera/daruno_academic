<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAMISEMI / NECTA grading scales and school curriculum frameworks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grading_systems')) {
            Schema::create('grading_systems', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 40)->nullable();
                // primary | o_level | a_level | all
                $table->string('education_stage', 30)->default('all');
                $table->foreignId('education_level_id')->nullable()->constrained('education_levels')->nullOnDelete();
                // necta_secondary | primary | custom
                $table->string('scale_type', 40)->default('necta_secondary');
                $table->decimal('pass_mark', 5, 2)->default(30);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'education_stage', 'is_active']);
                $table->unique(['school_id', 'code']);
            });
        }

        if (! Schema::hasTable('grading_bands')) {
            Schema::create('grading_bands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('grading_system_id')->constrained('grading_systems')->cascadeOnDelete();
                $table->string('grade', 10);
                $table->decimal('min_percent', 5, 2)->default(0);
                $table->decimal('max_percent', 5, 2)->nullable();
                $table->unsignedTinyInteger('points')->default(5);
                $table->string('remark')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['grading_system_id', 'min_percent']);
            });
        }

        if (! Schema::hasTable('curricula')) {
            Schema::create('curricula', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 40)->nullable();
                // primary | o_level | a_level | pre_primary | all
                $table->string('education_stage', 30)->default('all');
                $table->foreignId('education_level_id')->nullable()->constrained('education_levels')->nullOnDelete();
                $table->string('version', 40)->nullable(); // e.g. 2019, 2023
                $table->string('authority', 80)->nullable(); // TAMISEMI / MoEST
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->index(['school_id', 'education_stage', 'is_active']);
            });
        }

        if (! Schema::hasTable('curriculum_subjects')) {
            Schema::create('curriculum_subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->boolean('is_compulsory')->default(true);
                $table->unsignedTinyInteger('weekly_periods')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['curriculum_id', 'subject_id']);
            });
        }

        if (! Schema::hasTable('curriculum_topics')) {
            Schema::create('curriculum_topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();
                $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
                $table->string('title');
                $table->string('code', 40)->nullable();
                $table->unsignedTinyInteger('term_number')->nullable();
                $table->unsignedSmallInteger('week_from')->nullable();
                $table->unsignedSmallInteger('week_to')->nullable();
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['curriculum_id', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_topics');
        Schema::dropIfExists('curriculum_subjects');
        Schema::dropIfExists('curricula');
        Schema::dropIfExists('grading_bands');
        Schema::dropIfExists('grading_systems');
    }
};
