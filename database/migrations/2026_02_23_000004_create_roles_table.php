<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->foreignId('role_id')
                ->after('phone')
                ->nullable()
                ->constrained('roles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->string('role', 50)->default('parent')->index();
        });

        Schema::dropIfExists('roles');
    }
};

