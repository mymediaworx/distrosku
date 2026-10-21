<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 0.19.6: fulfillment lives on the sale. Every sale is counter pickup unless the user chose Delivery (or Ship).
 *  - sales_orders.ship_to_id becomes nullable (a counter-pickup sale has no ship-to).
 *  - Orders with no fulfillment yet are backfilled: open ones -> pickup (unless already on a delivery stop -> delivery);
 *    closed ones keep their history: POS -> pickup, everything else -> delivery. Ship-to ids already on orders are kept.
 * Re-runnable: each step checks the current state first; only rows with a NULL fulfillment are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'fulfillment')) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->string('fulfillment', 10)->nullable());
        }
        $col = collect(Schema::getColumns('sales_orders'))->firstWhere('name', 'ship_to_id');
        if ($col && ! $col['nullable']) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->unsignedBigInteger('ship_to_id')->nullable()->change());
        }

        $open = ['draft', 'confirmed', 'picked'];
        $onStop = Schema::hasTable('delivery_stops')
            ? DB::table('delivery_stops')->whereIn('status', ['pending', 'delivered', 'partial'])->pluck('sales_order_id')->all() : [];
        $n = ['open_pickup' => 0, 'open_delivery' => 0, 'closed_pickup' => 0, 'closed_delivery' => 0];
        $n['open_delivery'] = DB::table('sales_orders')->whereNull('fulfillment')->whereIn('status', $open)->whereIn('id', $onStop ?: [0])->update(['fulfillment' => 'delivery']);
        $n['open_pickup'] = DB::table('sales_orders')->whereNull('fulfillment')->whereIn('status', $open)->update(['fulfillment' => 'pickup']);
        $n['closed_pickup'] = DB::table('sales_orders')->whereNull('fulfillment')->where('channel', 'pos')->update(['fulfillment' => 'pickup']);
        $n['closed_delivery'] = DB::table('sales_orders')->whereNull('fulfillment')->update(['fulfillment' => 'delivery']);

        if (array_sum($n) > 0) {
            $msg = '0.19.6: sales fulfillment backfilled: '.json_encode($n).' (ship-to ids kept).';
            Log::info($msg);
            if (Schema::hasTable('activity_logs')) {
                try {
                    DB::table('activity_logs')->insert(['event' => 'order_fulfillment_migrated', 'description' => $msg,
                        'new_values' => json_encode($n), 'user_name' => 'upgrade', 'created_at' => now()]);
                } catch (\Throwable $e) {
                    Log::warning('0.19.6: could not write the activity log entry: '.$e->getMessage());
                }
            }
        }
    }

    public function down(): void
    {
        // ship_to_id stays nullable (pickup sales have none); fulfillment values stay.
    }
};
