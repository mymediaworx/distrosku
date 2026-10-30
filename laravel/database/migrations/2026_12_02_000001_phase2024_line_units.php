<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#25). Additive and re-runnable. Sales order and purchase order lines remember the unit they were entered in
 * (case, carton...) and how many of that unit. Stock, prices, costs, excise and GL stay in the product's stock unit:
 * `qty` is still the stock-unit quantity and is always unit_qty x the unit's factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_order_lines' => 'sol', 'purchase_order_lines' => 'pol'] as $table => $p) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'product_unit_id')) {
                Schema::table($table, function (Blueprint $t) use ($p) {
                    $t->foreignId('product_unit_id')->nullable()->constrained('product_units', 'id', "{$p}_unit_fk")->nullOnDelete();
                });
            }
            if (! Schema::hasColumn($table, 'unit_qty')) {
                Schema::table($table, fn (Blueprint $t) => $t->decimal('unit_qty', 12, 4)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['sales_order_lines' => 'sol', 'purchase_order_lines' => 'pol'] as $table => $p) {
            if (Schema::hasColumn($table, 'product_unit_id')) {
                Schema::table($table, function (Blueprint $t) use ($p) {
                    $t->dropForeign("{$p}_unit_fk");
                    $t->dropColumn('product_unit_id');
                });
            }
            if (Schema::hasColumn($table, 'unit_qty')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('unit_qty'));
            }
        }
    }
};
