<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('smokeless_type', 10)->nullable()->after('thc_mg'); // snuff | chew (tax_class snuff_chew)
            $t->decimal('net_weight_oz', 10, 4)->nullable()->after('smokeless_type'); // per consumer unit
            $t->unsignedInteger('units_per_case')->default(1)->after('unit');   // consumer units in the sold unit (e.g. 5-can roll = 5)
            $t->unsignedInteger('sticks_per_unit')->nullable()->after('units_per_case'); // cigarettes: carton = 200
            $t->boolean('premium_cigar')->default(false)->after('pact_covered');
            $t->boolean('ky_tax_paid_by_supplier')->default(false)->after('premium_cigar');
        });

        Schema::table('vendors', function (Blueprint $t) {
            $t->boolean('ky_tobacco_licensed')->default(false)->after('is_manufacturer');
            $t->string('fein', 20)->nullable()->after('ky_tobacco_licensed');
            $t->string('ky_license_number', 40)->nullable()->after('fein');
            $t->string('address')->nullable();
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->string('fein', 20)->nullable()->after('type');
        });

        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->decimal('excise_accrued', 14, 4)->default(0)->after('qty_received');
        });

        Schema::table('journals', function (Blueprint $t) {
            $t->foreignId('reversal_of_id')->nullable()->after('total')->constrained('journals')->nullOnDelete();
            $t->timestamp('reversed_at')->nullable()->after('reversal_of_id');
        });

        Schema::create('sequences', function (Blueprint $t) {
            $t->string('name', 20)->primary();
            $t->unsignedBigInteger('next_value');
            $t->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $t) {
            $t->id();
            $t->string('jurisdiction', 2);
            $t->string('tax_type', 40); // see App\Services\KyExcise
            $t->decimal('rate', 12, 6);
            $t->string('basis', 20); // per_unit, per_increment, percent
            $t->decimal('increment_oz', 8, 4)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('needs_verification')->default(true);
            $t->string('source')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['jurisdiction', 'tax_type', 'effective_from']);
        });

        Schema::create('tax_filings', function (Blueprint $t) {
            $t->id();
            $t->string('report_type', 40); // ky_subjobber_cigarette, ky_tobacco_distributor
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 20)->default('draft'); // draft, filed
            $t->json('summary')->nullable();
            $t->json('data')->nullable();
            $t->decimal('tax_due', 14, 4)->default(0);
            $t->string('confirmation')->nullable();
            $t->timestamp('filed_at')->nullable();
            $t->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['report_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_filings');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('sequences');
        Schema::table('journals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reversal_of_id');
            $t->dropColumn('reversed_at');
        });
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn('excise_accrued'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('fein'));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['ky_tobacco_licensed', 'fein', 'ky_license_number', 'address']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['smokeless_type', 'net_weight_oz', 'units_per_case', 'sticks_per_unit', 'premium_cigar', 'ky_tax_paid_by_supplier']));
    }
};
