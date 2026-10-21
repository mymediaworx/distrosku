<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.19.2: BrandWorx Marketing sync is one-way (ERP -> Marketing). Re-runnable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('marketing_leads'); // 0.19.1 inbound leads: no longer used
        Schema::table('customers', function (Blueprint $t) {
            if (! Schema::hasColumn('customers', 'state_tax_id')) {
                $t->string('state_tax_id', 40)->nullable();
            }
            if (! Schema::hasColumn('customers', 'ghl_created_by_erp')) {
                $t->boolean('ghl_created_by_erp')->default(false); // contact was created (not matched) by the ERP
            }
        });
        // 0.19.1 inbound settings are dead; remove them (the webhook secret especially).
        if (Schema::hasTable('settings')) {
            \Illuminate\Support\Facades\DB::table('settings')->whereIn('key', ['mkt_inbound_secret', 'mkt_inbound_minutes', 'mkt_inbound_since', 'mkt_inbound_last_run'])->delete();
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            foreach (['state_tax_id', 'ghl_created_by_erp'] as $c) {
                if (Schema::hasColumn('customers', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
