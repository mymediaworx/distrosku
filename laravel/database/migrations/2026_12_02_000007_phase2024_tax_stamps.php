<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#40, part 2). Additive and re-runnable. Cigarette tax stamping for distributors who are stamping agents
 * (OFF by default: Company Settings > Compliance). Stamp purchases from the state, affixing (unstamped SKU -> stamped SKU),
 * a reconciliation, and which state's stamp a SKU carries (stamped stock can't be shipped to another state).
 * No GL postings: stamp accounting is set up with the distributor's CPA before this is turned on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'stamp_state')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('stamp_state', 2)->nullable());
        }
        if (! Schema::hasTable('tax_stamp_purchases')) {
            Schema::create('tax_stamp_purchases', function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->unsignedSmallInteger('pack_size')->default(20); // stamps for packs of 20 or 25
                $t->unsignedInteger('stamps');
                $t->decimal('unit_cost', 12, 4)->default(0);
                $t->decimal('total', 14, 2)->default(0);
                $t->date('purchased_on');
                $t->string('reference', 80)->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
                $t->index(['state', 'pack_size'], 'tsp_state_size_idx');
            });
        }
        if (! Schema::hasTable('tax_stamp_affixings')) {
            Schema::create('tax_stamp_affixings', function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->unsignedSmallInteger('pack_size')->default(20);
                $t->unsignedInteger('packs');
                $t->foreignId('from_product_id')->constrained('products', 'id', 'tsa_from_fk');
                $t->foreignId('to_product_id')->constrained('products', 'id', 'tsa_to_fk');
                $t->foreignId('warehouse_id')->constrained();
                $t->date('affixed_on');
                $t->string('notes', 255)->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
                $t->index(['state', 'pack_size'], 'tsa_state_size_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_stamp_affixings');
        Schema::dropIfExists('tax_stamp_purchases');
        if (Schema::hasColumn('products', 'stamp_state')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('stamp_state'));
        }
    }
};
