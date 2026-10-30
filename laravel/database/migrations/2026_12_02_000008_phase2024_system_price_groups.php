<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#43, Michael 2026-10-30). Re-runnable. The Standard and Wholesale price groups get fixed codes STANDARD and WHOLESALE
 * on every install (customers in Wholesale are not charged sales tax). An existing group named "Wholesale"/"Standard" keeps its
 * id (customers and price rules stay linked) and only gets the fixed code; one is created if missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_groups')) {
            return;
        }
        foreach (['WHOLESALE' => 'Wholesale', 'STANDARD' => 'Standard'] as $code => $name) {
            if (DB::table('customer_groups')->where('code', $code)->exists()) {
                continue;
            }
            $byName = DB::table('customer_groups')->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->orderBy('id')->first();
            if ($byName) {
                DB::table('customer_groups')->where('id', $byName->id)->update(['code' => $code, 'name' => $name, 'updated_at' => now()]);
            } else {
                DB::table('customer_groups')->insert(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // codes stay: reverting would unlink nothing but would make them editable again
    }
};
