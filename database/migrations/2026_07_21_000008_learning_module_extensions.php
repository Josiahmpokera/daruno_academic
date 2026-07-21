<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning resources (TAMISEMI-aligned digital library) +
 * smart classroom / assignment workflow extensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_resources')) {
            Schema::create('learning_resources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                // note | past_paper | video | digital_book
                $table->string('type', 30);
                $table->string('title');
                $table->text('description')->nullable();
                $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
                $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $table->foreignId('education_level_id')->nullable()->constrained('education_levels')->nullOnDelete();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
                // File or external media
                $table->string('file_path')->nullable();
                $table->string('external_url')->nullable();
                $table->string('mime_type', 80)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                // Past paper metadata (NECTA / mock)
                $table->string('exam_body', 40)->nullable(); // NECTA, school, mock
                $table->unsignedSmallInteger('exam_year')->nullable();
                $table->string('paper_code', 40)->nullable();
                $table->string('class_level', 40)->nullable(); // STD I–VII, Form I–VI
                $table->string('language', 20)->nullable(); // en, sw
                $table->string('status', 20)->default('published'); // draft, published, archived
                $table->boolean('is_featured')->default(false);
                $table->unsignedInteger('view_count')->default(0);
                $table->unsignedInteger('download_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['school_id', 'type', 'status']);
                $table->index(['school_id', 'subject_id']);
            });
        }

        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (! Schema::hasColumn('courses', 'academic_year_id')) {
                    $table->foreignId('academic_year_id')->nullable()->after('teacher_id')
                        ->constrained('academic_years')->nullOnDelete();
                }
                if (! Schema::hasColumn('courses', 'term_id')) {
                    $table->foreignId('term_id')->nullable()->after('academic_year_id')
                        ->constrained('terms')->nullOnDelete();
                }
                if (! Schema::hasColumn('courses', 'status')) {
                    $table->string('status', 20)->default('active')->after('description');
                }
                if (! Schema::hasColumn('courses', 'code')) {
                    $table->string('code', 40)->nullable()->after('title');
                }
                if (! Schema::hasColumn('courses', 'cover_url')) {
                    $table->string('cover_url')->nullable()->after('status');
                }
            });
        }

        if (Schema::hasTable('lessons')) {
            Schema::table('lessons', function (Blueprint $table) {
                if (! Schema::hasColumn('lessons', 'summary')) {
                    $table->string('summary')->nullable()->after('title');
                }
                if (! Schema::hasColumn('lessons', 'duration_minutes')) {
                    $table->unsignedInteger('duration_minutes')->nullable()->after('content');
                }
                if (! Schema::hasColumn('lessons', 'status')) {
                    $table->string('status', 20)->default('published')->after('position');
                }
                if (! Schema::hasColumn('lessons', 'video_url')) {
                    $table->string('video_url')->nullable()->after('content');
                }
            });
        }

        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table) {
                if (! Schema::hasColumn('assignments', 'status')) {
                    $table->string('status', 20)->default('published')->after('type');
                }
                if (! Schema::hasColumn('assignments', 'instructions')) {
                    $table->text('instructions')->nullable()->after('description');
                }
                if (! Schema::hasColumn('assignments', 'attachment_path')) {
                    $table->string('attachment_path')->nullable()->after('instructions');
                }
                if (! Schema::hasColumn('assignments', 'allow_late')) {
                    $table->boolean('allow_late')->default(true)->after('max_score');
                }
            });
        }

        if (Schema::hasTable('assignment_submissions')) {
            Schema::table('assignment_submissions', function (Blueprint $table) {
                if (! Schema::hasColumn('assignment_submissions', 'status')) {
                    $table->string('status', 20)->default('submitted')->after('feedback');
                }
                if (! Schema::hasColumn('assignment_submissions', 'graded_at')) {
                    $table->dateTime('graded_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('assignment_submissions', 'graded_by')) {
                    $table->foreignId('graded_by')->nullable()->after('graded_at')
                        ->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_resources');
    }
};
