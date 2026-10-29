<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.20. Additive and re-runnable.
 * - products.taxes (json): the taxes charged on top of the price, e.g. [{"name":"KY excise","basis":"per_unit","amount":"0.25"}].
 *   Nothing is configured by default, so existing invoices and totals are unchanged until a product gets a tax.
 * - sales_order_lines.tax_per_unit / tax_detail / tax_total and sales_orders.tax_total: the tax snapshot used on the invoice.
 * - quick_counts: counts typed on the no-login Quick Tools page. They never change stock by themselves; an admin applies or dismisses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'taxes')) {
            Schema::table('products', fn (Blueprint $t) => $t->json('taxes')->nullable());
        }
        Schema::table('sales_order_lines', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_order_lines', 'tax_per_unit')) {
                $t->decimal('tax_per_unit', 14, 4)->default(0);
            }
            if (! Schema::hasColumn('sales_order_lines', 'tax_total')) {
                $t->decimal('tax_total', 14, 4)->default(0);
            }
            if (! Schema::hasColumn('sales_order_lines', 'tax_detail')) {
                $t->json('tax_detail')->nullable();
            }
        });
        if (! Schema::hasColumn('sales_orders', 'tax_total')) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->decimal('tax_total', 14, 4)->default(0));
        }
        if (! Schema::hasTable('quick_counts')) {
            Schema::create('quick_counts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $t->decimal('counted_qty', 14, 4);
                $t->string('counted_by', 100);
                $t->string('note', 255)->nullable();
                $t->string('status', 12)->default('pending')->index(); // pending, applied, dismissed
                $t->decimal('on_hand_at_review', 14, 4)->nullable();
                $t->decimal('applied_delta', 14, 4)->nullable();
                $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_counts');
    }
};
