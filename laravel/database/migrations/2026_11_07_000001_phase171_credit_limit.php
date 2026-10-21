<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.17.1: credit-limit override on sales orders (who, when, why). Re-run safe. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['credit_override_by' => fn (Blueprint $t) => $t->unsignedBigInteger('credit_override_by')->nullable(),
            'credit_override_at' => fn (Blueprint $t) => $t->timestamp('credit_override_at')->nullable(),
            'credit_override_reason' => fn (Blueprint $t) => $t->string('credit_override_reason', 500)->nullable()] as $col => $fn) {
            if (! Schema::hasColumn('sales_orders', $col)) {
                Schema::table('sales_orders', $fn);
            }
        }
    }

    public function down(): void
    {
        foreach (['credit_override_by', 'credit_override_at', 'credit_override_reason'] as $col) {
            if (Schema::hasColumn('sales_orders', $col)) {
                Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
