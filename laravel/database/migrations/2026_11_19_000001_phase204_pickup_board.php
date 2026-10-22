<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.4: Counter pickup board. A lightweight board stage on sales orders (the status machine is unchanged):
 *  board_stage = 'picking' while a Confirmed pickup order is being picked; board_stage_at / board_stage_by = last move.
 * Re-runnable: every step checks first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'board_stage' => fn (Blueprint $t) => $t->string('board_stage', 20)->nullable(),
            'board_stage_at' => fn (Blueprint $t) => $t->dateTime('board_stage_at')->nullable(),
            'board_stage_by' => fn (Blueprint $t) => $t->unsignedBigInteger('board_stage_by')->nullable(),
        ];
        foreach ($cols as $c => $fn) {
            if (! Schema::hasColumn('sales_orders', $c)) {
                Schema::table('sales_orders', $fn);
            }
        }
        if (! Schema::hasIndex('sales_orders', 'so_fulfill_status_idx')) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->index(['fulfillment', 'status'], 'so_fulfill_status_idx'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('sales_orders', 'so_fulfill_status_idx')) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->dropIndex('so_fulfill_status_idx'));
        }
        foreach (['board_stage', 'board_stage_at', 'board_stage_by'] as $c) {
            if (Schema::hasColumn('sales_orders', $c)) {
                Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
