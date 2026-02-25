<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type', 50)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'key']);
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->string('key');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'key']);
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->string('name');
            $table->string('url');
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('request_body')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->string('channel', 30);
            $table->string('key');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'channel', 'key'], 'notification_templates_unique');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('channel', 30);
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('sent');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'channel', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('system_settings');
    }
};

