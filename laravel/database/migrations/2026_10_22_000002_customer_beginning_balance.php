<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->decimal('beginning_balance', 12, 4)->default(0)->after('credit_limit'); // negative = credit
            $t->date('beginning_balance_date')->nullable()->after('beginning_balance');
            $t->decimal('beginning_balance_applied', 12, 4)->default(0)->after('beginning_balance_date'); // net payments/credits applied
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->boolean('applies_to_opening')->default(false)->after('sales_order_id');
        });
        Schema::create('customer_balance_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('old_balance', 12, 4);
            $t->decimal('new_balance', 12, 4);
            $t->date('old_date')->nullable();
            $t->date('new_date')->nullable();
            $t->foreignId('journal_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_balance_audits');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('applies_to_opening'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['beginning_balance', 'beginning_balance_date', 'beginning_balance_applied']));
    }
};
