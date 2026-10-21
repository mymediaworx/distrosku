<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.18.0: product eligibility registry (hemp/THC gating, FDA vape status, state vape directories, MSA) + COAs.
 * Re-run safe (hasTable / hasColumn guards). Index names kept short.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'products' => [
                'product_subtype' => fn (Blueprint $t) => $t->string('product_subtype', 20)->nullable(), // cbd|hemp|kratom|vape
                'thc_total_mg' => fn (Blueprint $t) => $t->decimal('thc_total_mg', 12, 4)->nullable(),   // total THC per container
                'thc_serving_mg' => fn (Blueprint $t) => $t->decimal('thc_serving_mg', 12, 4)->nullable(),
                'synthetic_cannabinoid' => fn (Blueprint $t) => $t->boolean('synthetic_cannabinoid')->default(false),
                'flavored' => fn (Blueprint $t) => $t->boolean('flavored')->default(false),
                'fda_status' => fn (Blueprint $t) => $t->string('fda_status', 20)->nullable(), // authorized|pending_pmta|denied|none
            ],
            'sales_orders' => [
                'ship_date' => fn (Blueprint $t) => $t->date('ship_date')->nullable(),
                'elig_override_by' => fn (Blueprint $t) => $t->unsignedBigInteger('elig_override_by')->nullable(),
                'elig_override_at' => fn (Blueprint $t) => $t->timestamp('elig_override_at')->nullable(),
                'elig_override_reason' => fn (Blueprint $t) => $t->string('elig_override_reason', 500)->nullable(),
                'elig_warnings' => fn (Blueprint $t) => $t->json('elig_warnings')->nullable(),
            ],
        ];
        foreach ($cols as $table => $list) {
            foreach ($list as $col => $fn) {
                if (! Schema::hasColumn($table, $col)) {
                    Schema::table($table, $fn);
                }
            }
        }

        if (! Schema::hasTable('product_coas')) {
            Schema::create('product_coas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('file_path')->nullable();
                $t->string('original_name', 190)->nullable();
                $t->string('lab', 190)->nullable();
                $t->string('batch_lot', 100)->nullable();
                $t->date('tested_on')->nullable();
                $t->date('expires_on')->nullable();
                $t->decimal('thc_total_mg', 12, 4)->nullable();
                $t->string('notes', 500)->nullable();
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
                $t->index(['product_id', 'expires_on'], 'pcoa_prod_exp');
            });
        }

        if (! Schema::hasTable('vape_directory_listings')) {
            Schema::create('vape_directory_listings', function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->date('listed_from');
                $t->date('listed_to')->nullable();
                $t->string('listing_ref', 100)->nullable();
                $t->string('source', 250)->nullable();
                $t->timestamps();
                $t->index(['state', 'product_id'], 'vdl_state_prod');
            });
        }

        if (! Schema::hasTable('eligibility_rules')) {
            Schema::create('eligibility_rules', function (Blueprint $t) {
                $t->id();
                $t->string('key', 60)->nullable()->unique('elig_key_uq'); // system rules (seeded) have a key
                $t->string('name', 190);
                $t->string('level', 10)->default('state');   // federal|state
                $t->string('state', 2)->nullable();          // null on a state rule = every state
                $t->string('county', 100)->nullable();
                $t->string('city', 100)->nullable();
                // which products the rule looks at (all set selectors must match)
                $t->string('subtype', 20)->nullable();
                $t->string('tax_class', 30)->nullable();
                // conditions: any one true = rule fires (no conditions = applies to every matched product)
                $t->decimal('thc_total_max_mg', 12, 4)->nullable();
                $t->decimal('thc_serving_max_mg', 12, 4)->nullable();
                $t->boolean('thc_unknown_fires')->default(false);
                $t->boolean('synthetic_fires')->default(false);
                $t->boolean('flavored_fires')->default(false);
                $t->json('fda_statuses')->nullable();
                $t->boolean('needs_msa_directory')->default(false);
                $t->boolean('needs_vape_directory')->default(false);
                $t->boolean('needs_current_coa')->default(false);
                $t->string('action', 10)->default('block'); // block|warn
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->boolean('enabled')->default(false);
                $t->boolean('verify_with_counsel')->default(false);
                $t->text('source_note')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['enabled', 'effective_from'], 'elig_en_from');
            });
        }

        $now = now();
        $sys = [
            // Existing MSA directory blocking now runs as this registry rule (same behaviour; disable here to switch off).
            ['key' => 'msa_directory', 'name' => 'State MSA directory (cigarettes / RYO)', 'level' => 'state', 'state' => null,
                'needs_msa_directory' => true, 'action' => 'block', 'effective_from' => '2000-01-01', 'enabled' => true, 'verify_with_counsel' => false,
                'source_note' => 'Built-in: brand family must be on the ship-to state MSA directory list loaded under Compliance > MSA directory. Strict mode (no list loaded = block) is in Settings.'],
            // PLACEHOLDER: disabled draft. Thresholds intentionally blank; set them only after counsel review.
            ['key' => 'federal_hemp_2026', 'name' => 'Federal hemp definition change (DRAFT, verify with counsel)', 'level' => 'federal', 'state' => null,
                'subtype' => 'hemp', 'synthetic_fires' => true, 'thc_total_max_mg' => null, 'thc_unknown_fires' => false,
                'action' => 'block', 'effective_from' => '2026-12-11', 'enabled' => false, 'verify_with_counsel' => true,
                'source_note' => 'PLACEHOLDER, verify with counsel before enabling. Federal hemp definition change reported effective 2026-12-11 (moved from 2026-11-12). '
                    .'No legal threshold has been entered: set "Max total THC per container (mg)" (and per serving if needed) and the products it covers only after counsel confirms the final text. '
                    .'Synthetic-cannabinoid SKUs are flagged. Use Reports > Inventory at risk with "include draft rules" to preview impact.'],
        ];
        foreach ($sys as $r) {
            if (! \Illuminate\Support\Facades\DB::table('eligibility_rules')->where('key', $r['key'])->exists()) {
                \Illuminate\Support\Facades\DB::table('eligibility_rules')->insert($r + ['version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_rules');
        Schema::dropIfExists('vape_directory_listings');
        Schema::dropIfExists('product_coas');
        foreach (['products' => ['product_subtype', 'thc_total_mg', 'thc_serving_mg', 'synthetic_cannabinoid', 'flavored', 'fda_status'],
            'sales_orders' => ['ship_date', 'elig_override_by', 'elig_override_at', 'elig_override_reason', 'elig_warnings']] as $table => $list) {
            foreach ($list as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
