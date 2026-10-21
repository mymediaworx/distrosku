<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 10: units of measure (case > carton > pack), extra barcodes per item/unit, lot + expiry tracking (FEFO, recall).
 *  Re-runnable over a partly applied state: every table, column and index is guarded; index names are explicit (<= 64 chars). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_units')) {
            Schema::create('product_units', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('code', 10);                          // CS, CTN, PK, EA ...
                $t->string('name', 40);                          // Case, Carton, Pack
                $t->decimal('factor', 12, 4);                    // base (stock) units in one of this unit
                $t->timestamps();
            });
        }
        $this->ensureIndex('product_units', ['product_id', 'code'], 'product_units_product_id_code_unique', true);
        if (! Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete(); // null = base unit
                $t->string('code', 64);
                $t->string('note', 60)->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('product_barcodes', ['code'], 'product_barcodes_code_unique', true);
        $this->addColumns('products', [
            'track_lots' => fn (Blueprint $t) => $t->boolean('track_lots')->default(false),
            'base_unit_name' => fn (Blueprint $t) => $t->string('base_unit_name', 20)->default('Each'), // what one stock unit is called (e.g. Pack)
        ]);
        if (! Schema::hasTable('lots')) {
            Schema::create('lots', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('lot_number', 60);
                $t->date('expires_on')->nullable();
                $t->boolean('on_hold')->default(false);          // recalled / quarantined: never allocated
                $t->timestamps();
            });
        }
        $this->ensureIndex('lots', ['product_id', 'lot_number'], 'lots_product_id_lot_number_unique', true);
        if (! Schema::hasTable('lot_stocks')) {
            Schema::create('lot_stocks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('lot_id')->constrained()->cascadeOnDelete();
                $t->foreignId('warehouse_id')->constrained();
                $t->decimal('qty', 12, 4)->default(0);
                $t->timestamps();
            });
        }
        $this->ensureIndex('lot_stocks', ['lot_id', 'warehouse_id'], 'lot_stocks_lot_id_warehouse_id_unique', true);
        if (! Schema::hasTable('lot_movements')) {
            Schema::create('lot_movements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('lot_id')->constrained()->cascadeOnDelete();
                $t->foreignId('warehouse_id')->constrained();
                $t->decimal('qty', 12, 4);                       // signed
                $t->string('type', 20);                          // receipt, sale, adjust
                $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('ship_to_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('note')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('lot_movements', ['lot_id', 'type'], 'lot_movements_lot_id_type_index');
        if (! Schema::hasTable('pick_line_lots')) {
            Schema::create('pick_line_lots', function (Blueprint $t) {
                $t->id();
                $t->foreignId('pick_list_line_id')->constrained()->cascadeOnDelete();
                $t->foreignId('lot_id')->constrained()->cascadeOnDelete();
                $t->decimal('qty_allocated', 12, 4)->default(0);
                $t->decimal('qty_picked', 12, 4)->default(0);
                $t->timestamps();
            });
        }
        $this->ensureIndex('pick_line_lots', ['pick_list_line_id', 'lot_id'], 'pick_line_lots_pick_list_line_id_lot_id_unique', true);
        $this->addColumns('purchase_orders', [
            'over_receipt_pct' => fn (Blueprint $t) => $t->decimal('over_receipt_pct', 6, 2)->nullable(), // null = global setting
        ]);
        if (! Schema::hasTable('warehouse_events')) {
            Schema::create('warehouse_events', function (Blueprint $t) {
                $t->id();
                $t->string('type', 30);                          // short_pick, over_receipt
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $t->decimal('qty', 12, 4)->default(0);
                $t->string('reason')->nullable();
                $t->json('details')->nullable();
                $t->timestamps();
            });
        }
        $this->addColumns('receiving_scans', [
            'lot_number' => fn (Blueprint $t) => $t->string('lot_number', 60)->nullable(),
            'expires_on' => fn (Blueprint $t) => $t->date('expires_on')->nullable(),
            'unit_code' => fn (Blueprint $t) => $t->string('unit_code', 10)->nullable(),
        ]);
    }

    public function down(): void
    {
        foreach (['lot_number', 'expires_on', 'unit_code'] as $c) {
            if (Schema::hasColumn('receiving_scans', $c)) {
                Schema::table('receiving_scans', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        Schema::dropIfExists('warehouse_events');
        if (Schema::hasColumn('purchase_orders', 'over_receipt_pct')) {
            Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('over_receipt_pct'));
        }
        foreach (['pick_line_lots', 'lot_movements', 'lot_stocks', 'lots', 'product_barcodes', 'product_units'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (['track_lots', 'base_unit_name'] as $c) {
            if (Schema::hasColumn('products', $c)) {
                Schema::table('products', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }

    /** Re-run safety (MySQL DDL is not transactional): add an index only if none with this name exists yet. */
    private function ensureIndex(string $table, array $cols, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
    }

    /** Add each missing column on its own, so a half-applied ALTER is completed rather than skipped. */
    private function addColumns(string $table, array $defs): void
    {
        foreach ($defs as $col => $fn) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $col)) {
                Schema::table($table, $fn);
            }
        }
    }
};
