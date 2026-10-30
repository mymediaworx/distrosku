<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.20.24 (#40). Additive and re-runnable: the QuickBooks account name each GL account exports to (blank = same name). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts') && ! Schema::hasColumn('accounts', 'qb_name')) {
            Schema::table('accounts', fn (Blueprint $t) => $t->string('qb_name', 100)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'qb_name')) {
            Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('qb_name'));
        }
    }
};
