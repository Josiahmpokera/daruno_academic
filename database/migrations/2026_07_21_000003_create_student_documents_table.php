<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_documents')) {
            Schema::create('student_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('title');
                $table->string('document_type', 60)->default('other');
                // birth_certificate, transfer_letter, medical_report, photo, id, other
                $table->string('file_name')->nullable();
                $table->string('file_path')->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('reference_number', 120)->nullable();
                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 30)->default('active'); // active, archived
                $table->timestamps();
                $table->index(['school_id', 'student_id']);
                $table->index(['school_id', 'document_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
