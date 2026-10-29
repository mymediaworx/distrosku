<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #36 traceability (0.20.15): optional lot on sale lines, COAs linked to a lot, a COA chosen at receiving,
 * the lot "Needs review" list (lot-tracked sale with no assignable lot) and the recall contacted log.
 * Additive and re-runnable; deletes nothing. Stock quantities and GL are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_order_lines', 'lot_id')) {
            Schema::table('sales_order_lines', fn (Blueprint $t) => $t->foreignId('lot_id')->nullable()->constrained('lots')->nullOnDelete());
        }
        if (! Schema::hasColumn('product_coas', 'lot_id')) {
            Schema::table('product_coas', fn (Blueprint $t) => $t->foreignId('lot_id')->nullable()->constrained('lots')->nullOnDelete());
        }
        if (! Schema::hasColumn('receiving_scans', 'product_coa_id')) {
            Schema::table('receiving_scans', fn (Blueprint $t) => $t->foreignId('product_coa_id')->nullable()->constrained('product_coas')->nullOnDelete());
        }
        if (! Schema::hasTable('lot_review_items')) {
            Schema::create('lot_review_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $t->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $t->decimal('qty', 14, 4);
                $t->string('reason', 40);   // no_lot_stock | several_lots | lot_short | lot_unavailable
                $t->string('status', 20)->default('open')->index(); // open | resolved
                $t->foreignId('lot_id')->nullable()->constrained('lots')->nullOnDelete(); // lot chosen when resolved
                $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('resolved_at')->nullable();
                $t->text('note')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('lot_contacts')) {
            Schema::create('lot_contacts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('lot_id')->constrained('lots')->cascadeOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $t->foreignId('ship_to_id')->nullable()->constrained('ship_tos')->nullOnDelete();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // who made contact
                $t->timestamp('contacted_at');
                $t->string('method', 20); // phone | email | text | in_person | letter | other
                $t->text('note')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // forward-only (keeps data)
    }
};
