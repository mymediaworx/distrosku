<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.20.24 (#10). Additive and re-runnable: Company Settings > Brand > Brand color for the POS, warehouse, driver, portal and quick-tools screens. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'brand_color')) {
            Schema::table('companies', fn (Blueprint $t) => $t->string('brand_color', 7)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'brand_color')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('brand_color'));
        }
    }
};
