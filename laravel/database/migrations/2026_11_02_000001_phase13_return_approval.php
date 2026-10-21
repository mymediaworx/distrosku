<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 13: KY return approval + month lock, versioned amendments, XML snapshots; count auto-approval; PACT default by tax class. */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('tax_filings', [
            'version' => fn (Blueprint $t) => $t->unsignedInteger('version')->default(1),
            'amends_id' => fn (Blueprint $t) => $t->foreignId('amends_id')->nullable()->constrained('tax_filings')->nullOnDelete(),
            'amendment_reason' => fn (Blueprint $t) => $t->string('amendment_reason', 500)->nullable(),
            'approved_by' => fn (Blueprint $t) => $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(),
            'approved_at' => fn (Blueprint $t) => $t->timestamp('approved_at')->nullable(),
            'xml' => fn (Blueprint $t) => $t->longText('xml')->nullable(),                 // XML snapshot taken at approval (schema-valid)
            'superseded_at' => fn (Blueprint $t) => $t->timestamp('superseded_at')->nullable(), // set when an amendment is approved
        ]);
        $this->addColumns('inventory_counts', [
            'auto_approved' => fn (Blueprint $t) => $t->boolean('auto_approved')->default(false),
        ]);
        // PACT covered defaults ON for cigarette, RYO, smokeless and vapor (editable per product).
        DB::table('products')->whereIn('tax_class', ['cigarette_stamped', 'ryo', 'snuff_chew', 'vapor'])->where('pact_covered', false)->update(['pact_covered' => true]);
    }

    public function down(): void
    {
        foreach (['amends_id', 'approved_by'] as $c) {
            if (Schema::hasColumn('tax_filings', $c)) {
                Schema::table('tax_filings', fn (Blueprint $t) => $t->dropConstrainedForeignId($c));
            }
        }
        foreach (['version', 'amendment_reason', 'approved_at', 'xml', 'superseded_at'] as $c) {
            if (Schema::hasColumn('tax_filings', $c)) {
                Schema::table('tax_filings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        if (Schema::hasColumn('inventory_counts', 'auto_approved')) {
            Schema::table('inventory_counts', fn (Blueprint $t) => $t->dropColumn('auto_approved'));
        }
    }

    /** Re-run safety (MySQL DDL is not transactional): add an index only if none with this name exists yet. */
    private function ensureIndex(string $table, array $cols, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
    }

    /** Add each missing column on its own, so a half-applied ALTER is completed rather than skipped. */
    private function addColumns(string $table, array $defs): void
    {
        foreach ($defs as $col => $fn) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $col)) {
                Schema::table($table, $fn);
            }
        }
    }
};
