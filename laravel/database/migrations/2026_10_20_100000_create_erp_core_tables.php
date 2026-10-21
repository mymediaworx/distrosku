<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 32)->default('sales')->after('email');
            $t->boolean('active')->default(true)->after('role');
        });

        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->boolean('is_licensed_tobacco')->default(true);
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state', 2)->default('KY');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 50)->unique();
            $t->string('upc', 20)->nullable()->index();
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('manufacturer')->nullable();
            $t->string('category')->nullable();
            $t->string('tax_class', 32)->default('general')->index();
            $t->boolean('pact_covered')->default(false);
            $t->string('hemp_type', 32)->nullable();
            $t->decimal('thc_mg', 10, 4)->nullable();
            $t->string('unit', 20)->default('each');
            $t->decimal('cost', 12, 4)->default(0);
            $t->decimal('price', 12, 4)->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('inventories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 12, 4)->default(0);
            $t->decimal('reserved', 12, 4)->default(0);
            $t->timestamps();
            $t->unique(['product_id', 'warehouse_id']);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->decimal('qty', 12, 4); // signed: + in, - out
            $t->decimal('unit_cost', 12, 4)->default(0);
            $t->string('type', 32); // receipt, sale, adjustment, transfer_in, transfer_out
            $t->nullableMorphs('reference');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('type', 30)->default('retailer'); // retailer, wholesaler, subjobber
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('terms', 30)->default('Net 15');
            $t->decimal('credit_limit', 12, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('ship_tos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('county')->nullable();
            $t->string('state', 2);
            $t->string('zip', 10)->nullable();
            $t->timestamps();
        });

        Schema::create('customer_licenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ship_to_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 40); // tobacco_retail, tobacco_wholesale, hemp, kratom, resale
            $t->string('number');
            $t->string('state', 2);
            $t->date('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->boolean('is_manufacturer')->default(false);
            $t->string('state', 2)->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('vendor_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->string('status', 20)->default('draft'); // draft, ordered, received, cancelled
            $t->date('order_date');
            $t->timestamp('received_at')->nullable();
            $t->decimal('total', 14, 4)->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->decimal('qty', 12, 4);
            $t->decimal('unit_cost', 12, 4);
            $t->decimal('qty_received', 12, 4)->default(0);
            $t->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('ship_to_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->string('channel', 20)->default('office'); // office, pos, portal
            $t->string('status', 20)->default('draft'); // draft, confirmed, picked, delivered, invoiced, cancelled
            $t->date('order_date');
            $t->decimal('subtotal', 14, 4)->default(0);
            $t->decimal('total', 14, 4)->default(0);
            $t->decimal('amount_paid', 14, 4)->default(0);
            $t->json('compliance_reasons')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('invoiced_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('sales_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->decimal('qty', 12, 4);
            $t->decimal('unit_price', 12, 4);
            $t->decimal('unit_cost', 12, 4)->default(0);
            $t->decimal('line_total', 14, 4)->default(0);
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 14, 4);
            $t->string('method', 20)->default('cash'); // cash, check, card, ach
            $t->string('reference')->nullable();
            $t->date('paid_on');
            $t->timestamps();
        });

        Schema::create('delivery_manifests', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->date('delivery_date');
            $t->foreignId('warehouse_id')->constrained();
            $t->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('open'); // open, out, closed
            $t->timestamps();
        });

        Schema::create('delivery_stops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('delivery_manifest_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sales_order_id')->constrained();
            $t->unsignedInteger('sequence')->default(1);
            $t->string('status', 20)->default('pending'); // pending, delivered, refused
            $t->string('received_by')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });

        Schema::create('buydowns', function (Blueprint $t) {
            $t->id();
            $t->string('manufacturer');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->decimal('amount_per_unit', 12, 4);
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->unsignedInteger('max_units')->nullable();
            $t->decimal('units_sold', 12, 4)->default(0);
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });

        Schema::create('compliance_rule_versions', function (Blueprint $t) {
            $t->id();
            $t->string('jurisdiction', 2)->index();
            $t->string('tax_class', 32)->nullable();
            $t->string('hemp_type', 32)->nullable();
            $t->string('rule', 32); // ban, thc_limit, license_required
            $t->boolean('banned')->default(false);
            $t->decimal('max_thc_mg', 10, 4)->nullable();
            $t->string('license_type', 40)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('version', 30)->default('local-1');
            $t->string('source')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 10)->unique();
            $t->string('name');
            $t->string('type', 20); // asset, liability, equity, revenue, expense
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('journals', function (Blueprint $t) {
            $t->id();
            $t->date('entry_date');
            $t->string('memo');
            $t->nullableMorphs('source');
            $t->decimal('total', 14, 4)->default(0);
            $t->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained();
            $t->decimal('debit', 14, 4)->default(0);
            $t->decimal('credit', 14, 4)->default(0);
            $t->string('memo')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['journal_lines', 'journals', 'accounts', 'compliance_rule_versions', 'buydowns', 'delivery_stops',
            'delivery_manifests', 'payments', 'sales_order_lines', 'sales_orders', 'purchase_order_lines',
            'purchase_orders', 'vendors', 'customer_licenses', 'ship_tos', 'customers', 'stock_movements',
            'inventories', 'products', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
