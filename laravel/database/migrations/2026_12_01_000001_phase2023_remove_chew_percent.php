<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.23. Re-runnable. Removes the "chew at 15% of wholesale" KY rate that the 0.20.17 seeder added
 * (source "Owner decision 2026-10-27"). revenue.ky.gov, checked 2026-10-29, still taxes chewing tobacco per unit
 * ($0.19 / $0.40 / $0.65 + $0.19 per 4 oz over 16 oz, KRS 138.140), so chew stays on those rates.
 * Only that seeded row is removed; any chew_percent row added another way is left alone. No other rate is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_rates')) {
            return;
        }
        DB::table('tax_rates')
            ->where('jurisdiction', 'KY')
            ->where('tax_type', 'chew_percent')
            ->where('source', 'Owner decision 2026-10-27')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally not restored: the 15% chew rate does not match Kentucky law.
    }
};
