<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#43). Re-runnable, no schema change. Adds the "Sales Tax Exemption Certificate" license type next to
 * "Resale Certificate". Sales tax itself stays OFF until an owner turns it on (Company Settings > Sales tax).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('license_types') && ! DB::table('license_types')->where('code', 'sales_tax_exempt')->exists()) {
            DB::table('license_types')->insert(['code' => 'sales_tax_exempt', 'name' => 'Sales Tax Exemption Certificate', 'state' => null,
                'sort' => 45, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // license types are reference data; nothing to undo
    }
};
