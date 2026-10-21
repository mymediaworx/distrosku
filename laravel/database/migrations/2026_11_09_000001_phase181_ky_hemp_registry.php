<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.18.1: KY CHFS hemp-derived cannabinoid product registry (state directory entries), THC-vape selector on rules,
 * product form + cannabinoid flag, listing provenance. Seeds the enabled KY THC-vape registry rule (block).
 * Re-run safe (hasTable / hasColumn / key guards). Index names are short.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'products' => [
                'product_form' => fn (Blueprint $t) => $t->string('product_form', 20)->nullable(),          // vape|gummy|beverage|...
                'hemp_cannabinoid' => fn (Blueprint $t) => $t->boolean('hemp_cannabinoid')->default(false), // contains a hemp-derived cannabinoid
            ],
            'eligibility_rules' => [
                'selector' => fn (Blueprint $t) => $t->string('selector', 30)->nullable(), // thc_vape
            ],
            'vape_directory_listings' => [
                'registry_entry_id' => fn (Blueprint $t) => $t->unsignedBigInteger('registry_entry_id')->nullable(),
                'manual' => fn (Blueprint $t) => $t->boolean('manual')->default(true),
                'note' => fn (Blueprint $t) => $t->string('note', 250)->nullable(),
                'listed_by' => fn (Blueprint $t) => $t->unsignedBigInteger('listed_by')->nullable(),
            ],
        ];
        foreach ($cols as $table => $list) {
            foreach ($list as $col => $fn) {
                if (! Schema::hasColumn($table, $col)) {
                    Schema::table($table, $fn);
                }
            }
        }

        if (! Schema::hasTable('state_registry_entries')) {
            Schema::create('state_registry_entries', function (Blueprint $t) {
                $t->id();
                $t->string('registry', 30);              // ky_hemp
                $t->string('state', 2);
                $t->string('record_id', 40);             // the registry's own record id
                $t->string('brand', 190)->nullable();
                $t->string('product_name', 250);
                $t->string('product_type', 60)->nullable();
                $t->string('other_type', 100)->nullable();
                $t->string('adult_use', 10)->nullable();
                $t->string('match_key', 250)->nullable(); // normalised "brand name"
                $t->string('name_key', 250)->nullable();  // normalised product name only
                $t->date('first_seen_on');
                $t->date('last_seen_on');
                $t->date('removed_on')->nullable();
                $t->timestamps();
                $t->unique(['registry', 'record_id'], 'sre_reg_rec_uq');
                $t->index(['state', 'match_key'], 'sre_state_key');
                $t->index(['state', 'name_key'], 'sre_state_name');
            });
        }

        $now = now();
        $url = 'https://redcap.chfs.ky.gov/surveys/?__report=LXDTXPJH9E4RAY8R';
        if (! DB::table('eligibility_rules')->where('key', 'ky_thc_vape_registry')->exists()) {
            DB::table('eligibility_rules')->insert([
                'key' => 'ky_thc_vape_registry', 'name' => 'KY: THC vape must be on the CHFS hemp product registry',
                'level' => 'state', 'state' => 'KY', 'selector' => 'thc_vape', 'needs_vape_directory' => true,
                'action' => 'block', 'effective_from' => $now->toDateString(), 'enabled' => false, 'verify_with_counsel' => false,
                'source_note' => 'DISABLED until CHFS lists UPCs on the registry (0.18.2). Kentucky CHFS "Hemp-derived Cannabinoid Registered Products" report: '.$url
                    .' . A THC vape (vape form + hemp/CBD/THC cannabinoid) sold into KY must have a current KY listing: imported from that report under Compliance > KY hemp registry, or marked listed by hand with a date. '
                    .'Effective date = the day this rule was added (the report shows no dates).',
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('eligibility_rules')->where('key', 'ky_thc_vape_registry')->delete();
        Schema::dropIfExists('state_registry_entries');
        foreach (['products' => ['product_form', 'hemp_cannabinoid'], 'eligibility_rules' => ['selector'],
            'vape_directory_listings' => ['registry_entry_id', 'manual', 'note', 'listed_by']] as $table => $list) {
            foreach ($list as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
