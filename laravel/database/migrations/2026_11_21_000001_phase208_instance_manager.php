<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.20.8 instance manager link: replay-protection nonces + "managed" flags on the global lists. Re-runnable; deletes nothing. */
return new class extends Migration
{
    public const MANAGED = ['license_types', 'eligibility_rules', 'compliance_rule_versions', 'tax_rates'];

    public function up(): void
    {
        if (! Schema::hasTable('dba_nonces')) {
            Schema::create('dba_nonces', function (Blueprint $t) {
                $t->string('nonce', 64)->primary();
                $t->timestamp('created_at')->index();
            });
        }
        foreach (self::MANAGED as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'managed')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('managed')->default(false);
                $t->string('managed_code', 160)->nullable()->index();
                $t->unsignedInteger('managed_version')->nullable();
                $t->boolean('managed_overridable')->default(false);
                $t->boolean('local_override')->default(false);
            });
        }
    }

    public function down(): void
    {
        // forward-only (keeps data)
    }
};
