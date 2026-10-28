<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.21. Additive and re-runnable.
 * customers.walk_in: walk-in / cash customers cannot buy regulated products (tobacco, vapor, CBD/hemp, kratom).
 * The CASH customer is flagged here (and is also recognised by its code). Products are NOT changed: "regulated"
 * is the existing product tax class.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'walk_in')) {
            Schema::table('customers', fn (Blueprint $t) => $t->boolean('walk_in')->default(false));
        }
        DB::table('customers')->whereRaw('UPPER(code) = ?', ['CASH'])->update(['walk_in' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'walk_in')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('walk_in'));
        }
    }
};
