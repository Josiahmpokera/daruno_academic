<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_levels')) {
            Schema::create('education_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('streams')) {
            Schema::create('streams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        Schema::table('classrooms', function (Blueprint $table) {
            if (! Schema::hasColumn('classrooms', 'education_level_id')) {
                $table->unsignedBigInteger('education_level_id')->nullable()->after('code');
            }
            if (! Schema::hasColumn('classrooms', 'stream_id')) {
                $table->unsignedBigInteger('stream_id')->nullable()->after('education_level_id');
            }
            if (! Schema::hasColumn('classrooms', 'capacity')) {
                $table->unsignedInteger('capacity')->nullable()->after('stream_id');
            }
            if (! Schema::hasColumn('classrooms', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('capacity');
            }
        });

        try {
            Schema::table('classrooms', function (Blueprint $table) {
                $table->foreign('education_level_id')->references('id')->on('education_levels')->nullOnDelete();
                $table->foreign('stream_id')->references('id')->on('streams')->nullOnDelete();
            });
        } catch (\Throwable) {
            // already exist
        }
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            try {
                $table->dropForeign(['education_level_id']);
                $table->dropForeign(['stream_id']);
            } catch (\Throwable) {
            }
            foreach (['education_level_id', 'stream_id', 'capacity', 'is_active'] as $col) {
                if (Schema::hasColumn('classrooms', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('streams');
        Schema::dropIfExists('education_levels');
    }
};
