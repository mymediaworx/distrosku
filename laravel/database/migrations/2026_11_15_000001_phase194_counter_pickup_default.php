<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 0.19.4: fulfillment method. Counter pickup is the default for every ship-to and for new orders.
 * Before this, fulfillment was not modeled (every confirmed office/portal order was a delivery candidate).
 * Re-runnable: the flip of existing ship-tos happens only when the column is first added; the count is logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ship_tos', 'fulfillment')) {
            $n = DB::table('ship_tos')->count();
            Schema::table('ship_tos', fn (Blueprint $t) => $t->string('fulfillment', 10)->default('pickup')); // existing rows -> pickup
            DB::table('ship_tos')->update(['fulfillment' => 'pickup']);
            $this->log($n);
        }
        if (! Schema::hasColumn('sales_orders', 'fulfillment')) {
            // null on existing orders = legacy behaviour (delivery candidate), so in-flight deliveries are not disturbed
            Schema::table('sales_orders', fn (Blueprint $t) => $t->string('fulfillment', 10)->nullable());
        }
    }

    private function log(int $n): void
    {
        $msg = "0.19.4: set {$n} existing ship-to(s) to counter pickup (delivery days and routes kept as data).";
        Log::info($msg);
        if (Schema::hasTable('activity_logs')) {
            try {
                DB::table('activity_logs')->insert(['event' => 'shipto_fulfillment_migrated', 'description' => $msg,
                    'new_values' => json_encode(['count' => $n, 'fulfillment' => 'pickup']), 'user_name' => 'upgrade', 'created_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('0.19.4: could not write the activity log entry: '.$e->getMessage());
            }
        }
        if (Schema::hasTable('settings')) {
            \App\Models\Setting::put('phase194_shiptos_flipped', (string) $n);
        }
    }

    public function down(): void
    {
        foreach (['ship_tos', 'sales_orders'] as $tb) {
            if (Schema::hasColumn($tb, 'fulfillment')) {
                Schema::table($tb, fn (Blueprint $t) => $t->dropColumn('fulfillment'));
            }
        }
    }
};
