<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 14 pricing: customer groups, price rules (contract / group / qty break / dated), promotions, line price source + override log, buydown/rebate accruals. Re-run safe. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_groups')) {
            Schema::create('customer_groups', function (Blueprint $t) {
                $t->id();
                $t->string('code', 30)->unique('cust_groups_code_uq');
                $t->string('name');
                $t->timestamps();
            });
        }
        $this->addColumns('customers', [
            'customer_group_id' => fn (Blueprint $t) => $t->foreignId('customer_group_id')->nullable()->constrained('customer_groups', 'id', 'cust_group_fk')->nullOnDelete(),
        ]);

        if (! Schema::hasTable('price_rules')) {
            Schema::create('price_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained('products', 'id', 'prule_product_fk')->cascadeOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers', 'id', 'prule_customer_fk')->cascadeOnDelete();
                $t->foreignId('customer_group_id')->nullable()->constrained('customer_groups', 'id', 'prule_group_fk')->cascadeOnDelete();
                $t->decimal('min_qty', 12, 4)->default(1);
                $t->decimal('price', 12, 4);
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->boolean('active')->default(true);
                $t->string('note')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('price_rules', ['product_id', 'active', 'min_qty'], 'prule_prod_active_idx');

        if (! Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('kind', 10)->default('percent'); // percent | amount (per unit)
                $t->decimal('value', 12, 4);
                $t->foreignId('product_id')->nullable()->constrained('products', 'id', 'promo_product_fk')->cascadeOnDelete();
                $t->foreignId('department_id')->nullable()->constrained('departments', 'id', 'promo_dept_fk')->cascadeOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers', 'id', 'promo_customer_fk')->cascadeOnDelete();
                $t->foreignId('customer_group_id')->nullable()->constrained('customer_groups', 'id', 'promo_group_fk')->cascadeOnDelete();
                $t->date('starts_on');
                $t->date('ends_on');
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        $this->ensureIndex('promotions', ['active', 'starts_on', 'ends_on'], 'promo_active_dates_idx');

        $this->addColumns('sales_order_lines', [
            'list_price' => fn (Blueprint $t) => $t->decimal('list_price', 12, 4)->nullable(),
            'quoted_price' => fn (Blueprint $t) => $t->decimal('quoted_price', 12, 4)->nullable(),
            'price_source' => fn (Blueprint $t) => $t->string('price_source', 40)->nullable(),
            'price_rule_id' => fn (Blueprint $t) => $t->foreignId('price_rule_id')->nullable()->constrained('price_rules', 'id', 'sol_prule_fk')->nullOnDelete(),
            'promotion_id' => fn (Blueprint $t) => $t->foreignId('promotion_id')->nullable()->constrained('promotions', 'id', 'sol_promo_fk')->nullOnDelete(),
            'price_overridden' => fn (Blueprint $t) => $t->boolean('price_overridden')->default(false),
        ]);

        if (! Schema::hasTable('price_override_logs')) {
            Schema::create('price_override_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines', 'id', 'pol_line_fk')->nullOnDelete();
                $t->foreignId('sales_order_id')->nullable()->constrained('sales_orders', 'id', 'pol_order_fk')->nullOnDelete();
                $t->foreignId('product_id')->nullable()->constrained('products', 'id', 'pol_product_fk')->nullOnDelete();
                $t->foreignId('user_id')->nullable()->constrained('users', 'id', 'pol_user_fk')->nullOnDelete();
                $t->decimal('quoted_price', 12, 4)->nullable();
                $t->string('quoted_source', 40)->nullable();
                $t->decimal('old_price', 12, 4)->nullable();
                $t->decimal('new_price', 12, 4);
                $t->string('channel', 20)->nullable();
                $t->timestamps();
            });
        }

        $this->addColumns('buydowns', [
            'kind' => fn (Blueprint $t) => $t->string('kind', 12)->default('buydown'), // buydown | rebate
            'vendor_id' => fn (Blueprint $t) => $t->foreignId('vendor_id')->nullable()->constrained('vendors', 'id', 'buydown_vendor_fk')->nullOnDelete(),
            'program_code' => fn (Blueprint $t) => $t->string('program_code', 50)->nullable(),
        ]);
        if (! Schema::hasTable('buydown_accruals')) {
            Schema::create('buydown_accruals', function (Blueprint $t) {
                $t->id();
                $t->foreignId('buydown_id')->constrained('buydowns', 'id', 'bda_buydown_fk')->cascadeOnDelete();
                $t->foreignId('sales_order_id')->constrained('sales_orders', 'id', 'bda_order_fk')->cascadeOnDelete();
                $t->foreignId('sales_order_line_id')->constrained('sales_order_lines', 'id', 'bda_line_fk')->cascadeOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers', 'id', 'bda_customer_fk')->nullOnDelete();
                $t->foreignId('product_id')->nullable()->constrained('products', 'id', 'bda_product_fk')->nullOnDelete();
                $t->date('sold_on');
                $t->decimal('qty', 12, 4);
                $t->decimal('amount_per_unit', 12, 4);
                $t->decimal('amount', 14, 4);
                $t->timestamp('claimed_at')->nullable();
                $t->string('claim_ref', 60)->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('buydown_accruals', ['buydown_id', 'sales_order_line_id'], 'bda_buydown_line_uq', true);
        $this->ensureIndex('buydown_accruals', ['sold_on', 'claimed_at'], 'bda_sold_claimed_idx');
    }

    public function down(): void
    {
        Schema::dropIfExists('buydown_accruals');
        Schema::dropIfExists('price_override_logs');
        foreach (['price_rule_id' => 'sol_prule_fk', 'promotion_id' => 'sol_promo_fk'] as $c => $fk) {
            if (Schema::hasColumn('sales_order_lines', $c)) {
                Schema::table('sales_order_lines', function (Blueprint $t) use ($c, $fk) { $t->dropForeign($fk); $t->dropColumn($c); });
            }
        }
        foreach (['list_price', 'quoted_price', 'price_source', 'price_overridden'] as $c) {
            if (Schema::hasColumn('sales_order_lines', $c)) {
                Schema::table('sales_order_lines', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        if (Schema::hasColumn('buydowns', 'vendor_id')) {
            Schema::table('buydowns', function (Blueprint $t) { $t->dropForeign('buydown_vendor_fk'); $t->dropColumn('vendor_id'); });
        }
        foreach (['kind', 'program_code'] as $c) {
            if (Schema::hasColumn('buydowns', $c)) {
                Schema::table('buydowns', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('price_rules');
        if (Schema::hasColumn('customers', 'customer_group_id')) {
            Schema::table('customers', function (Blueprint $t) { $t->dropForeign('cust_group_fk'); $t->dropColumn('customer_group_id'); });
        }
        Schema::dropIfExists('customer_groups');
    }

    private function ensureIndex(string $table, array $cols, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
    }

    private function addColumns(string $table, array $defs): void
    {
        foreach ($defs as $col => $fn) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $col)) {
                Schema::table($table, $fn);
            }
        }
    }
};
