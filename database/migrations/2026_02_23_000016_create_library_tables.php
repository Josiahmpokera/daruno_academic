<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn')->nullable();
            $table->string('category')->nullable();
            $table->string('shelf_location')->nullable();
            $table->unsignedInteger('total_copies')->default(0);
            $table->unsignedInteger('available_copies')->default(0);
            $table->timestamps();
            $table->index(['school_id', 'title']);
        });

        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('book_id')->constrained('library_books');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->foreignId('staff_id')->nullable()->constrained('staff');
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->date('returned_at')->nullable();
            $table->string('status', 20)->default('issued'); // issued, returned, overdue
            $table->decimal('fine_amount', 10, 2)->default(0);
            $table->timestamps();
            $table->index(['school_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_books');
    }
};

