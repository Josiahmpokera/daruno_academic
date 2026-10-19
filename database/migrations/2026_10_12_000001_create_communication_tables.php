<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: announcements + notice board, and parent messaging threads.
 *
 * Parents are not portal users yet (they live in student_guardians), so parent
 * threads reference the guardian record rather than a users row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            // announcement = broadcast delivered to recipients; notice = notice-board post
            $table->string('kind', 20)->default('announcement');
            $table->string('title', 200);
            $table->text('body');
            $table->string('category', 30)->default('general');
            // all | parents | staff | classes
            $table->string('audience', 20)->default('all');
            $table->json('classroom_ids')->nullable();
            $table->string('priority', 20)->default('normal'); // normal | important | urgent
            $table->json('channels')->nullable(); // portal, email, sms
            $table->string('status', 20)->default('draft'); // draft | scheduled | published | archived
            $table->boolean('is_pinned')->default(false);
            $table->boolean('show_on_board')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->json('delivery_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['school_id', 'kind', 'status']);
        });

        Schema::create('parent_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('guardian_id')->constrained('student_guardians');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->string('subject', 200);
            $table->string('status', 20)->default('open'); // open | closed
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_direction', 10)->nullable();
            $table->unsignedInteger('unread_count')->default(0); // unread inbound replies
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['school_id', 'status', 'last_message_at']);
        });

        Schema::create('parent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('thread_id')->constrained('parent_threads')->cascadeOnDelete();
            $table->string('direction', 10)->default('outbound'); // outbound | inbound
            $table->text('body');
            $table->json('channels')->nullable();
            $table->json('delivery')->nullable(); // { email: {status, error}, sms: {...}, portal: {...} }
            $table->foreignId('sender_user_id')->nullable()->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'sent_at']);
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('recipient', 190)->nullable()->after('channel');
            $table->string('source_type', 40)->nullable()->after('recipient');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('error', 255)->nullable()->after('status');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['recipient', 'source_type', 'source_id', 'error']);
        });
        Schema::dropIfExists('parent_messages');
        Schema::dropIfExists('parent_threads');
        Schema::dropIfExists('announcements');
    }
};
