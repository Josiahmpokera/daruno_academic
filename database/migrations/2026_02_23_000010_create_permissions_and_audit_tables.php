<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->string('module')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('permission_id')->constrained('permissions');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('permission_id')->constrained('permissions');
            $table->boolean('is_granted')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_permission');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
    }
};

