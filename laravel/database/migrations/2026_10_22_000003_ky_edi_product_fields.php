<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fields required by the KY Tobacco Products EDI Guide (Transactions element, Table 1 / Tables 3, 8, 9, 10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('manufacturer_fein', 9)->nullable()->after('manufacturer'); // ManufacturerEIN
            $t->string('msa_status', 10)->nullable()->after('manufacturer_fein');   // Table 8 (OPM/SPM/NPM/PM/N/A ...)
            $t->string('ky_state_desc', 20)->nullable()->after('msa_status');       // Table 3 override e.g. KY-PL2, KY-SS1
            $t->string('edi_unit_desc', 3)->nullable()->after('ky_state_desc');      // Table 10 e.g. CAN, PCH, BOX
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->string('city')->nullable()->after('address');
            $t->string('zip', 10)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['manufacturer_fein', 'msa_status', 'ky_state_desc', 'edi_unit_desc']));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['city', 'zip']));
    }
};
