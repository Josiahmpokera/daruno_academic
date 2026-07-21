<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops module industrial extensions:
 * inventory stock controls + PO lines, library categories/reservations,
 * transport maintenance & fees, hostel facilities & occupancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_items')) {
            Schema::table('stock_items', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_items', 'reorder_level')) {
                    $table->unsignedInteger('reorder_level')->default(0)->after('quantity_on_hand');
                }
                if (! Schema::hasColumn('stock_items', 'unit_cost')) {
                    $table->decimal('unit_cost', 12, 2)->default(0)->after('reorder_level');
                }
                if (! Schema::hasColumn('stock_items', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('school_id')
                        ->constrained('asset_categories')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_items', 'description')) {
                    $table->text('description')->nullable()->after('unit');
                }
                if (! Schema::hasColumn('stock_items', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('unit_cost');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_movements', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (! Schema::hasColumn('stock_movements', 'unit_cost')) {
                    $table->decimal('unit_cost', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('stock_movements', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('purchase_orders') && ! Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
                $table->string('description');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_orders', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (! Schema::hasColumn('purchase_orders', 'expected_at')) {
                    $table->date('expected_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('assets')) {
            Schema::table('assets', function (Blueprint $table) {
                if (! Schema::hasColumn('assets', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (! Schema::hasColumn('assets', 'condition')) {
                    $table->string('condition', 40)->nullable();
                }
            });
        }

        if (! Schema::hasTable('library_categories')) {
            Schema::create('library_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (Schema::hasTable('library_books')) {
            Schema::table('library_books', function (Blueprint $table) {
                if (! Schema::hasColumn('library_books', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('school_id')
                        ->constrained('library_categories')->nullOnDelete();
                }
                if (! Schema::hasColumn('library_books', 'publisher')) {
                    $table->string('publisher')->nullable();
                }
                if (! Schema::hasColumn('library_books', 'published_year')) {
                    $table->unsignedSmallInteger('published_year')->nullable();
                }
                if (! Schema::hasColumn('library_books', 'status')) {
                    $table->string('status', 20)->default('active');
                }
            });
        }

        if (! Schema::hasTable('library_reservations')) {
            Schema::create('library_reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('book_id')->constrained('library_books')->cascadeOnDelete();
                $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
                $table->date('reserved_on');
                $table->date('expires_on')->nullable();
                $table->string('status', 20)->default('active'); // active, fulfilled, cancelled, expired
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'status']);
            });
        }

        if (! Schema::hasTable('vehicle_maintenances')) {
            Schema::create('vehicle_maintenances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
                $table->date('serviced_on');
                $table->string('type', 40)->default('service'); // service, repair, inspection
                $table->string('description')->nullable();
                $table->decimal('cost', 12, 2)->default(0);
                $table->string('vendor')->nullable();
                $table->date('next_service_on')->nullable();
                $table->string('status', 20)->default('completed');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('transport_fees')) {
            Schema::create('transport_fees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
                $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                $table->string('title');
                $table->decimal('amount', 12, 2);
                $table->string('period', 20)->default('term'); // term, month, year, once
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hostels')) {
            Schema::create('hostels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('gender', 20)->default('mixed'); // male, female, mixed
                $table->string('warden_name')->nullable();
                $table->string('phone')->nullable();
                $table->unsignedInteger('capacity')->default(0);
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('hostel_rooms')) {
            Schema::create('hostel_rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('hostel_id')->constrained('hostels')->cascadeOnDelete();
                $table->string('name');
                $table->string('floor')->nullable();
                $table->unsignedInteger('capacity')->default(1);
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['hostel_id', 'name']);
            });
        }

        if (! Schema::hasTable('hostel_beds')) {
            Schema::create('hostel_beds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('room_id')->constrained('hostel_rooms')->cascadeOnDelete();
                $table->string('label');
                $table->string('status', 20)->default('available'); // available, occupied, maintenance
                $table->timestamps();
                $table->unique(['room_id', 'label']);
            });
        }

        if (! Schema::hasTable('hostel_allocations')) {
            Schema::create('hostel_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('hostel_id')->constrained('hostels')->cascadeOnDelete();
                $table->foreignId('room_id')->nullable()->constrained('hostel_rooms')->nullOnDelete();
                $table->foreignId('bed_id')->nullable()->constrained('hostel_beds')->nullOnDelete();
                $table->date('check_in_on');
                $table->date('check_out_on')->nullable();
                $table->string('status', 20)->default('checked_in'); // reserved, checked_in, checked_out
                $table->decimal('fee_amount', 12, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['school_id', 'status']);
            });
        }

        if (! Schema::hasTable('hostel_fees')) {
            Schema::create('hostel_fees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('hostel_id')->nullable()->constrained('hostels')->nullOnDelete();
                $table->string('title');
                $table->decimal('amount', 12, 2);
                $table->string('period', 20)->default('term');
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_fees');
        Schema::dropIfExists('hostel_allocations');
        Schema::dropIfExists('hostel_beds');
        Schema::dropIfExists('hostel_rooms');
        Schema::dropIfExists('hostels');
        Schema::dropIfExists('transport_fees');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('library_reservations');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('library_categories');
    }
};
