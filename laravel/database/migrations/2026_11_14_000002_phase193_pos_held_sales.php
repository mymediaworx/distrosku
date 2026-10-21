<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.19.3: POS Hold / Recall. A held sale is a saved cart only: no order, no stock reserved. Re-runnable. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_held_sales')) {
            return;
        }
        Schema::create('pos_held_sales', function (Blueprint $t) {
            $t->id();
            $t->string('label', 80)->nullable();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('ship_to_id')->nullable()->constrained('ship_tos')->nullOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // = location
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('register', 80)->nullable();
            $t->string('tender', 20)->nullable();
            $t->text('notes')->nullable();
            $t->json('cart');
            $t->decimal('total', 14, 4)->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'expires_at'], 'pos_held_wh_exp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_held_sales');
    }
};
