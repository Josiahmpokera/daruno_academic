<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open'); // open, in_progress, resolved, closed
            $table->string('priority', 20)->default('normal'); // low, normal, high
            $table->string('category', 50)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('helpdesk_tickets');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('user_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('type', 50)->nullable(); // bug, feature, general
            $table->text('message');
            $table->unsignedTinyInteger('rating')->nullable(); // 1-5
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('helpdesk_tickets');
    }
};

