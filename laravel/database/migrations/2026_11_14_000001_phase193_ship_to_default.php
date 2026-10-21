<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 0.19.3: default ship-to per customer (ship-tos now live on the customer page). Re-runnable. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ship_tos', 'is_default')) {
            Schema::table('ship_tos', fn (Blueprint $t) => $t->boolean('is_default')->default(false));
        }
        // backfill: customers without a default get their oldest ship-to
        $have = DB::table('ship_tos')->where('is_default', true)->pluck('customer_id');
        $ids = DB::table('ship_tos')->whereNotIn('customer_id', $have)->groupBy('customer_id')->selectRaw('min(id) as id')->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('ship_tos')->whereIn('id', $chunk->all())->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ship_tos', 'is_default')) {
            Schema::table('ship_tos', fn (Blueprint $t) => $t->dropColumn('is_default'));
        }
    }
};
