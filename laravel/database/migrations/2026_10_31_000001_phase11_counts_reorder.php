<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11: cycle / full inventory counts (freeze, blind, lot-aware, approval + GL posting) and reorder inputs.
 *  0.13.1: the count-line unique key got a short explicit name (the auto name was 65 chars, MySQL error 1059), and every
 *  table / column / index is guarded so this re-runs cleanly over the half-applied state that error leaves behind. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_counts')) {
            Schema::create('inventory_counts', function (Blueprint $t) {
                $t->id();
                $t->string('number', 30);
                $t->foreignId('warehouse_id')->constrained();
                $t->string('type', 10)->default('cycle');        // cycle, full
                $t->boolean('blind')->default(false);            // counters do not see expected qty
                $t->string('status', 12)->default('draft');      // draft, counting, submitted, posted, cancelled
                $t->json('scope')->nullable();                   // {bin_ids:[], product_ids:[]}
                $t->string('notes')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('started_at')->nullable();
                $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('submitted_at')->nullable();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('posted_at')->nullable();
                $t->foreignId('journal_id')->nullable()->constrained()->nullOnDelete();
                $t->decimal('variance_value', 14, 4)->default(0);
                $t->timestamps();
            });
        }
        $this->ensureIndex('inventory_counts', ['number'], 'inventory_counts_number_unique', true);
        $this->ensureIndex('inventory_counts', ['warehouse_id', 'status'], 'inventory_counts_warehouse_id_status_index');
        if (! Schema::hasTable('inventory_count_lines')) {
            Schema::create('inventory_count_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id')->constrained();
                $t->foreignId('bin_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('lot_id')->nullable()->constrained()->nullOnDelete(); // null = unlotted stock
                $t->decimal('expected', 12, 4)->default(0);
                $t->decimal('counted', 12, 4)->nullable();       // null = not counted yet
                $t->decimal('unit_cost', 12, 4)->default(0);
                $t->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('counted_at')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('inventory_count_lines', ['inventory_count_id', 'product_id', 'lot_id'], 'inv_count_lines_count_product_lot_unique', true);
        if (! Schema::hasTable('inventory_count_scans')) {
            Schema::create('inventory_count_scans', function (Blueprint $t) {
                $t->id();
                $t->foreignId('inventory_count_line_id')->constrained()->cascadeOnDelete();
                $t->string('code', 64)->nullable();
                $t->string('unit_code', 10)->nullable();
                $t->decimal('qty', 12, 4);                       // base units (signed: a manual set records the delta)
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
            });
        }
        $this->addColumns('products', [
            'preferred_vendor_id' => fn (Blueprint $t) => $t->foreignId('preferred_vendor_id')->nullable()->constrained('vendors')->nullOnDelete(),
            'lead_time_days' => fn (Blueprint $t) => $t->unsignedSmallInteger('lead_time_days')->nullable(),
            'reorder_qty' => fn (Blueprint $t) => $t->decimal('reorder_qty', 12, 4)->nullable(),   // order multiple / minimum
        ]);
        $this->addColumns('vendors', [
            'lead_time_days' => fn (Blueprint $t) => $t->unsignedSmallInteger('lead_time_days')->nullable(),
        ]);
    }

    public function down(): void
    {
        foreach (['inventory_count_scans', 'inventory_count_lines', 'inventory_counts'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('products', 'preferred_vendor_id')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('preferred_vendor_id'));
        }
        foreach (['lead_time_days', 'reorder_qty'] as $c) {
            if (Schema::hasColumn('products', $c)) {
                Schema::table('products', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        if (Schema::hasColumn('vendors', 'lead_time_days')) {
            Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('lead_time_days'));
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
