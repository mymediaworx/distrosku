<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_memos', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('sales_order_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->string('status', 20)->default('draft'); // draft, posted, void
            $t->date('memo_date');
            $t->string('reason')->nullable();
            $t->decimal('total', 14, 4)->default(0);
            $t->decimal('cost_total', 14, 4)->default(0);
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('credit_memo_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('credit_memo_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sales_order_line_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->decimal('qty', 12, 4);
            $t->decimal('unit_price', 12, 4)->default(0);
            $t->decimal('unit_cost', 12, 4)->default(0);
            $t->decimal('line_total', 14, 4)->default(0);
            $t->string('disposition', 20)->default('restock'); // restock, damage
            $t->timestamps();
        });

        Schema::table('sales_orders', function (Blueprint $t) {
            $t->decimal('amount_credited', 14, 4)->default(0)->after('amount_paid');
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->string('kind', 20)->default('payment')->after('method'); // payment, refund
            $t->string('status', 20)->default('posted')->after('kind');  // posted, voided
            $t->string('provider', 30)->nullable()->after('status');
            $t->string('provider_transaction_id', 60)->nullable()->after('provider');
            $t->foreignId('payment_method_id')->nullable()->after('provider_transaction_id');
            $t->foreignId('credit_memo_id')->nullable()->after('sales_order_id')->constrained()->nullOnDelete();
            $t->foreignId('refund_of_id')->nullable()->constrained('payments')->nullOnDelete();
        });

        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);
            $t->string('type', 10); // card, ach
            $t->string('customer_profile_id', 40);
            $t->string('payment_profile_id', 40);
            $t->string('label')->nullable(); // e.g. "Visa ••1111" / "Checking ••6789" (no PAN, no account number)
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 30);
            $t->string('action', 30); // create_profile, add_payment_profile, charge, refund, void
            $t->string('transaction_id', 60)->nullable();
            $t->decimal('amount', 14, 4)->nullable();
            $t->boolean('success')->default(false);
            $t->string('response_code', 20)->nullable();
            $t->string('message')->nullable();
            $t->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->text('value')->nullable(); // encrypted by the model
            $t->timestamps();
        });

        Schema::create('rules_syncs', function (Blueprint $t) {
            $t->id();
            $t->string('status', 20); // ok, unchanged, failed
            $t->string('version', 40)->nullable();
            $t->string('url')->nullable();
            $t->unsignedInteger('rules_upserted')->default(0);
            $t->unsignedInteger('rates_upserted')->default(0);
            $t->text('message')->nullable();
            $t->string('trigger', 20)->default('schedule'); // schedule, manual
            $t->timestamps();
        });

        foreach (['compliance_rule_versions', 'tax_rates'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('hub_id', 80)->nullable()->unique();
                $t->string('hub_version', 40)->nullable();
            });
        }

        Schema::table('tax_filings', function (Blueprint $t) {
            $t->decimal('amount_paid', 14, 4)->default(0)->after('tax_due');
            $t->date('paid_on')->nullable()->after('amount_paid');
            $t->string('payment_reference')->nullable()->after('paid_on');
        });
    }

    public function down(): void
    {
        Schema::table('tax_filings', fn (Blueprint $t) => $t->dropColumn(['amount_paid', 'paid_on', 'payment_reference']));
        foreach (['compliance_rule_versions', 'tax_rates'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['hub_id']);
                $t->dropColumn(['hub_id', 'hub_version']);
            });
        }
        Schema::dropIfExists('rules_syncs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_methods');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('refund_of_id');
            $t->dropConstrainedForeignId('credit_memo_id');
            $t->dropColumn(['kind', 'status', 'provider', 'provider_transaction_id', 'payment_method_id']);
        });
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn('amount_credited'));
        Schema::dropIfExists('credit_memo_lines');
        Schema::dropIfExists('credit_memos');
    }
};
