<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 7: MSA MULTICAT weekly file (docs/msa/MULTICAT_LAYOUT.md). Product / ship-to / customer MSA fields + submission archive. */
return new class extends Migration
{
    private const PRODUCT = ['msa_category_code', 'msa_items_per_unit', 'msa_units_per_sale', 'msa_upc', 'msa_description', 'msa_promo',
        'msa_promo_code', 'msa_promo_description', 'msa_shipper_flag', 'msa_mfr_product_code', 'msa_unit_size', 'msa_unit_size_desc', 'msa_exclude'];

    private const SHIP_TO = ['msa_shipping_number', 'msa_shipping_ext', 'msa_store_number', 'msa_tdlinx', 'msa_cash_and_carry', 'phone'];

    private const CUSTOMER = ['msa_class_of_trade', 'msa_promo_acceptance'];

    public function up(): void
    {
        if (! Schema::hasColumn('products', 'msa_category_code')) {
            Schema::table('products', function (Blueprint $t) {
                $t->string('msa_category_code', 6)->nullable();          // e.g. 003231
                $t->unsignedInteger('msa_items_per_unit')->nullable();   // sticks / cans / packs per MSA selling unit
                $t->decimal('msa_units_per_sale', 12, 6)->default(1);    // MSA selling units per 1 ERP unit (pack sold, carton reported = 0.1)
                $t->string('msa_upc', 14)->nullable();                   // selling-unit UPC override (blank = products.upc)
                $t->string('msa_description', 50)->nullable();           // override (blank = name)
                $t->boolean('msa_promo')->default(false);
                $t->string('msa_promo_code', 10)->nullable();
                $t->string('msa_promo_description', 50)->nullable();
                $t->string('msa_shipper_flag', 1)->nullable();           // S / C / null
                $t->string('msa_mfr_product_code', 14)->nullable();
                $t->decimal('msa_unit_size', 10, 2)->nullable();
                $t->string('msa_unit_size_desc', 10)->nullable();
                $t->boolean('msa_exclude')->default(false);
            });
        }
        if (! Schema::hasColumn('ship_tos', 'msa_shipping_number')) {
            Schema::table('ship_tos', function (Blueprint $t) {
                $t->string('msa_shipping_number', 8)->nullable();        // blank = ship-to id
                $t->string('msa_shipping_ext', 8)->nullable();
                $t->string('msa_store_number', 8)->nullable();
                $t->string('msa_tdlinx', 7)->nullable();
                $t->boolean('msa_cash_and_carry')->default(false);
                if (! Schema::hasColumn('ship_tos', 'phone')) {
                    $t->string('phone', 30)->nullable();
                }
            });
        }
        if (! Schema::hasColumn('customers', 'msa_class_of_trade')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->string('msa_class_of_trade', 20)->nullable();        // blank = mapped from customers.type
                $t->boolean('msa_promo_acceptance')->default(false);
            });
        }
        if (! Schema::hasTable('msa_submissions')) {
            Schema::create('msa_submissions', function (Blueprint $t) {
                $t->id();
                $t->date('week_ending')->index();
                $t->boolean('test')->default(true);
                $t->boolean('altria')->default(false);
                $t->string('file_name', 40);
                $t->string('path');
                $t->string('sha256', 64);
                $t->unsignedInteger('bid_count')->default(0);
                $t->unsignedInteger('sid_count')->default(0);
                $t->unsignedInteger('pur_count')->default(0);
                $t->decimal('qty_total', 16, 4)->default(0);
                $t->decimal('dollars_total', 16, 4)->nullable();
                $t->decimal('inventory_total', 16, 4)->default(0);
                $t->json('warnings')->nullable();
                $t->string('status', 20)->default('generated');          // generated, uploaded
                $t->timestamp('uploaded_at')->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('msa_submissions');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(self::CUSTOMER));
        Schema::table('ship_tos', fn (Blueprint $t) => $t->dropColumn(self::SHIP_TO));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(self::PRODUCT));
    }
};
