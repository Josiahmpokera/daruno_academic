<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name')->nullable();
            $table->string('plate_number')->unique();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('license_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes');
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->time('pickup_time')->nullable();
            $table->time('drop_time')->nullable();
            $table->timestamps();
        });

        Schema::create('student_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('route_id')->constrained('routes');
            $table->foreignId('stop_id')->nullable()->constrained('route_stops');
            $table->string('direction', 10)->default('to'); // to, from, both
            $table->timestamps();
            $table->unique(['school_id', 'student_id', 'route_id'], 'student_routes_unique');
        });

        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('route_id')->constrained('routes');
            $table->foreignId('driver_id')->nullable()->constrained('drivers');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'vehicle_id', 'recorded_at'], 'vehicle_location_logs_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_location_logs');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('student_routes');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};

