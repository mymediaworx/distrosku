<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.1: inbound PO tracking + receiving-team alerts.
 *  - purchase_orders: expected_at, carrier_code, carrier_name (LTL/other), tracking_numbers (JSON list), tracking_url, ordered_at.
 *  - warehouse_receivers: per-warehouse receiving users (who gets inbound alerts).
 *  - notifications: Laravel/Filament database (bell) notifications.
 * Re-runnable: every step checks first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'expected_at' => fn (Blueprint $t) => $t->date('expected_at')->nullable(),
            'carrier_code' => fn (Blueprint $t) => $t->string('carrier_code', 20)->nullable(),
            'carrier_name' => fn (Blueprint $t) => $t->string('carrier_name', 80)->nullable(),
            'tracking_numbers' => fn (Blueprint $t) => $t->json('tracking_numbers')->nullable(),
            'tracking_url' => fn (Blueprint $t) => $t->string('tracking_url', 500)->nullable(),
            'ordered_at' => fn (Blueprint $t) => $t->dateTime('ordered_at')->nullable(),
        ];
        foreach ($cols as $c => $fn) {
            if (! Schema::hasColumn('purchase_orders', $c)) {
                Schema::table('purchase_orders', $fn);
            }
        }
        if (! Schema::hasIndex('purchase_orders', 'po_status_expected_idx')) {
            Schema::table('purchase_orders', fn (Blueprint $t) => $t->index(['status', 'expected_at'], 'po_status_expected_idx'));
        }

        if (! Schema::hasTable('warehouse_receivers')) {
            Schema::create('warehouse_receivers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['warehouse_id', 'user_id'], 'wh_receivers_wh_user_uq');
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->morphs('notifiable');
                $t->text('data');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_receivers');
        if (Schema::hasIndex('purchase_orders', 'po_status_expected_idx')) {
            Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropIndex('po_status_expected_idx'));
        }
        foreach (['expected_at', 'carrier_code', 'carrier_name', 'tracking_numbers', 'tracking_url', 'ordered_at'] as $c) {
            if (Schema::hasColumn('purchase_orders', $c)) {
                Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
