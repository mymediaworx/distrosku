<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #26 offline sales: every sale a POS / van device recorded without a connection, as it arrived at sync.
 * The device's own UUID makes a resend harmless. A sale the server can't complete is kept for review, never dropped.
 * Re-runnable; deletes nothing. (Phase number left to Brandworx Dev.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offline_sales')) {
            return;
        }
        Schema::create('offline_sales', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('device_id', 64)->nullable()->index();
            $t->string('channel', 10)->default('pos');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();      // cashier on the device
            $t->foreignId('synced_by')->nullable()->constrained('users')->nullOnDelete();    // signed-in user when it reached the server
            $t->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->timestamp('sold_at')->nullable()->index();
            $t->string('tender', 20)->nullable();
            $t->decimal('total', 14, 4)->default(0);
            $t->json('payload');
            $t->string('status', 20)->default('pending')->index(); // pending | completed | needs_review | resolved
            $t->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->json('problems')->nullable();
            $t->json('warnings')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('synced_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only (keeps data)
    }
};
