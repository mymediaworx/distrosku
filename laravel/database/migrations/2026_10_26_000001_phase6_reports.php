<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6: sales rep on customers/orders (sales-by-rep report) and a product reorder point (low-stock KPI). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'sales_rep_id')) {
            Schema::table('customers', fn (Blueprint $t) => $t->foreignId('sales_rep_id')->nullable()->after('type')->constrained('users')->nullOnDelete());
        }
        if (! Schema::hasColumn('sales_orders', 'sales_rep_id')) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->foreignId('sales_rep_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete());
        }
        if (! Schema::hasColumn('products', 'reorder_point')) {
            Schema::table('products', fn (Blueprint $t) => $t->decimal('reorder_point', 12, 4)->nullable()->after('price'));
        }
        // Existing orders inherit the customer's rep (none assigned yet on upgrade, so this is a no-op until reps are set).
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('sales_rep_id'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropConstrainedForeignId('sales_rep_id'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('reorder_point'));
    }
};
