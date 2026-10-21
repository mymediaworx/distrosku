<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 9 warehouse scanning: bins, scan receiving sessions, put-away tasks, pick lists with scan-to-confirm, packing (cartons). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bins')) {
            Schema::create('bins', function (Blueprint $t) {
                $t->id();
                $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $t->string('code', 30);                          // e.g. A-01-03 (also the bin barcode)
                $t->string('zone', 30)->nullable();
                $t->unsignedInteger('pick_sequence')->default(0); // walk order for pick lists
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique(['warehouse_id', 'code']);
            });
        }
        if (! Schema::hasColumn('inventories', 'bin_id')) {
            Schema::table('inventories', fn (Blueprint $t) => $t->foreignId('bin_id')->nullable()->constrained('bins')->nullOnDelete()); // primary bin
        }
        if (! Schema::hasTable('receiving_sessions')) {
            Schema::create('receiving_sessions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('status', 20)->default('open');       // open, posted, cancelled
                $t->timestamp('posted_at')->nullable();
                $t->json('variances')->nullable();
                $t->timestamps();
            });
            Schema::create('receiving_scans', function (Blueprint $t) {
                $t->id();
                $t->foreignId('receiving_session_id')->constrained()->cascadeOnDelete();
                $t->foreignId('purchase_order_line_id')->constrained()->cascadeOnDelete();
                $t->decimal('qty', 12, 4);
                $t->string('code', 64)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('putaway_tasks')) {
            Schema::create('putaway_tasks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained();
                $t->foreignId('warehouse_id')->constrained();
                $t->foreignId('receiving_session_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('suggested_bin_id')->nullable()->constrained('bins')->nullOnDelete();
                $t->foreignId('bin_id')->nullable()->constrained('bins')->nullOnDelete();
                $t->decimal('qty', 12, 4);
                $t->string('status', 20)->default('open');       // open, done
                $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('done_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('pick_lists')) {
            Schema::create('pick_lists', function (Blueprint $t) {
                $t->id();
                $t->string('number', 30)->unique();
                $t->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
                $t->string('status', 20)->default('open');       // open, picked, packed, cancelled
                $t->foreignId('picker_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('picked_at')->nullable();
                $t->timestamp('packed_at')->nullable();
                $t->timestamps();
            });
            Schema::create('pick_list_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
                $t->foreignId('sales_order_line_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id')->constrained();
                $t->foreignId('bin_id')->nullable()->constrained('bins')->nullOnDelete();
                $t->decimal('qty', 12, 4);
                $t->decimal('qty_picked', 12, 4)->default(0);
                $t->decimal('qty_packed', 12, 4)->default(0);
                $t->timestamps();
            });
            Schema::create('packages', function (Blueprint $t) {
                $t->id();
                $t->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('seq');
                $t->string('status', 20)->default('open');       // open, closed
                $t->decimal('weight_lb', 10, 2)->nullable();
                $t->timestamps();
            });
            Schema::create('package_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('package_id')->constrained()->cascadeOnDelete();
                $t->foreignId('pick_list_line_id')->constrained()->cascadeOnDelete();
                $t->decimal('qty', 12, 4);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['package_lines', 'packages', 'pick_list_lines', 'pick_lists', 'putaway_tasks', 'receiving_scans', 'receiving_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('inventories', 'bin_id')) {
            Schema::table('inventories', fn (Blueprint $t) => $t->dropConstrainedForeignId('bin_id'));
        }
        Schema::dropIfExists('bins');
    }
};
