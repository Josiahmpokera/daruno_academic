<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('category_id')->nullable()->constrained('asset_categories');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('location')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'code']);
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('unit')->nullable(); // pcs, box, etc.
            $table->unsignedInteger('quantity_on_hand')->default(0);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('type', 20); // in, out, adjust
            $table->integer('quantity');
            $table->date('occurred_on')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'occurred_on']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            $table->string('number')->unique();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, ordered, received, cancelled
            $table->date('ordered_at')->nullable();
            $table->date('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('asset_categories');
    }
};

