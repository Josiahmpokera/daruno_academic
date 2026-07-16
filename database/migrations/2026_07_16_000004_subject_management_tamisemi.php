<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAMISEMI / NECTA-oriented subject management:
 * categories, enriched subjects, A/O-level combinations,
 * class subject assignment, teacher allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subject_categories')) {
            Schema::create('subject_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 40)->nullable();
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        Schema::table('subjects', function (Blueprint $table) {
            if (! Schema::hasColumn('subjects', 'subject_category_id')) {
                $table->unsignedBigInteger('subject_category_id')->nullable()->after('school_id');
            }
            if (! Schema::hasColumn('subjects', 'short_name')) {
                $table->string('short_name', 40)->nullable()->after('name');
            }
            if (! Schema::hasColumn('subjects', 'necta_code')) {
                $table->string('necta_code', 20)->nullable()->after('code');
            }
            if (! Schema::hasColumn('subjects', 'education_stage')) {
                // pre_primary | primary | ordinary_level | advanced_level | all
                $table->string('education_stage', 30)->default('all')->after('necta_code');
            }
            if (! Schema::hasColumn('subjects', 'is_compulsory')) {
                $table->boolean('is_compulsory')->default(true)->after('is_core');
            }
            if (! Schema::hasColumn('subjects', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_compulsory');
            }
            if (! Schema::hasColumn('subjects', 'weekly_periods')) {
                $table->unsignedTinyInteger('weekly_periods')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('subjects', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('weekly_periods');
            }
            if (! Schema::hasColumn('subjects', 'description')) {
                $table->text('description')->nullable()->after('sort_order');
            }
        });

        try {
            Schema::table('subjects', function (Blueprint $table) {
                $table->foreign('subject_category_id')
                    ->references('id')
                    ->on('subject_categories')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // already exists
        }

        if (! Schema::hasTable('subject_combinations')) {
            Schema::create('subject_combinations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 20);
                // advanced_level (default NECTA A-level) | ordinary_level package
                $table->string('education_stage', 30)->default('advanced_level');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['school_id', 'code']);
            });
        }

        if (! Schema::hasTable('subject_combination_subject')) {
            Schema::create('subject_combination_subject', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subject_combination_id')->constrained('subject_combinations')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->boolean('is_principal')->default(true);
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['subject_combination_id', 'subject_id'], 'combo_subject_unique');
            });
        }

        if (! Schema::hasTable('classroom_subjects')) {
            Schema::create('classroom_subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->boolean('is_compulsory')->default(true);
                $table->unsignedTinyInteger('periods_per_week')->nullable();
                $table->timestamps();
                $table->unique(
                    ['classroom_id', 'subject_id', 'academic_year_id'],
                    'classroom_subject_year_unique'
                );
            });
        }

        if (! Schema::hasTable('teacher_subject_allocations')) {
            Schema::create('teacher_subject_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
                $table->unsignedTinyInteger('periods_per_week')->nullable();
                $table->boolean('is_primary_teacher')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(
                    ['staff_id', 'subject_id', 'classroom_id', 'academic_year_id'],
                    'teacher_subject_class_year_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subject_allocations');
        Schema::dropIfExists('classroom_subjects');
        Schema::dropIfExists('subject_combination_subject');
        Schema::dropIfExists('subject_combinations');

        try {
            Schema::table('subjects', function (Blueprint $table) {
                $table->dropForeign(['subject_category_id']);
            });
        } catch (\Throwable) {
        }

        Schema::table('subjects', function (Blueprint $table) {
            foreach ([
                'subject_category_id',
                'short_name',
                'necta_code',
                'education_stage',
                'is_compulsory',
                'is_active',
                'weekly_periods',
                'sort_order',
                'description',
            ] as $col) {
                if (Schema::hasColumn('subjects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('subject_categories');
    }
};
