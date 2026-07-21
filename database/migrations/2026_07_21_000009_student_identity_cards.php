<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_id_cards')) {
            Schema::create('student_id_cards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('card_number', 40);
                $table->string('template_key', 40)->default('classic_green');
                $table->string('verification_token', 64)->unique();
                $table->string('barcode_value', 80)->nullable();
                $table->string('qr_payload', 500)->nullable();
                $table->string('status', 20)->default('active'); // active, revoked, expired, reissued
                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->unsignedInteger('print_count')->default(0);
                $table->timestamp('last_printed_at')->nullable();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'card_number']);
                $table->index(['school_id', 'student_id', 'status']);
            });
        }

        if (! Schema::hasTable('school_id_card_settings')) {
            Schema::create('school_id_card_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('default_template', 40)->default('classic_green');
                $table->string('card_prefix', 20)->default('SID');
                $table->unsignedSmallInteger('validity_years')->default(1);
                $table->string('front_footer', 120)->nullable();
                $table->string('back_note', 255)->nullable();
                $table->boolean('show_photo_placeholder')->default(true);
                $table->boolean('show_blood_group')->default(true);
                $table->boolean('show_guardian')->default(false);
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->unique('school_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_id_card_settings');
        Schema::dropIfExists('student_id_cards');
    }
};
