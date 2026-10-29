<?php

use App\Compliance\WalkInRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.22. Additive and re-runnable. Turns the walk-in flag on for cash-customer accounts whose account number was typed
 * a little differently from "CASH" ("Cash", " CASH ", "CASH-WALKIN"). Never turns a flag off. No product is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'walk_in')) {
            return;
        }
        $ids = DB::table('customers')->get(['id', 'code'])->filter(fn ($c) => WalkInRule::isCashCode($c->code))->pluck('id')->all();
        foreach (array_chunk($ids, 100) as $chunk) {
            DB::table('customers')->whereIn('id', $chunk)->update(['walk_in' => true]);
        }
    }

    public function down(): void
    {
        // flags are data; nothing to undo
    }
};
