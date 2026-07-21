<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login history for school security / audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('login_histories')) {
            Schema::create('login_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email')->nullable();
                $table->string('status', 20)->default('success'); // success, failed
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamp('logged_in_at')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'logged_in_at']);
            });
        }

        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'scope')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('scope', 20)->default('school')->after('label'); // platform|school|family
                $table->boolean('is_system')->default(false)->after('scope');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
