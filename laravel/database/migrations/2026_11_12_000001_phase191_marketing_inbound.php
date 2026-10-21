<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.19.1: BrandWorx Marketing inbound sync (contacts -> customers, leads). Re-run safe; short index names. */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'customers' => [
                'ghl_tags' => fn (Blueprint $t) => $t->json('ghl_tags')->nullable(),
                'ghl_dnd' => fn (Blueprint $t) => $t->boolean('ghl_dnd')->default(false),
                'ghl_stage' => fn (Blueprint $t) => $t->string('ghl_stage', 120)->nullable(),          // "Pipeline: Stage (status)"
                'ghl_opportunity_value' => fn (Blueprint $t) => $t->decimal('ghl_opportunity_value', 12, 2)->nullable(),
                'ghl_contact_updated_at' => fn (Blueprint $t) => $t->timestamp('ghl_contact_updated_at')->nullable(),
                'contact_fields_updated_at' => fn (Blueprint $t) => $t->timestamp('contact_fields_updated_at')->nullable(), // ERP email/phone change time
                'accept_marketing_contact_updates' => fn (Blueprint $t) => $t->boolean('accept_marketing_contact_updates')->default(false),
            ],
        ];
        foreach ($cols as $table => $list) {
            foreach ($list as $col => $fn) {
                if (! Schema::hasColumn($table, $col)) {
                    Schema::table($table, $fn);
                }
            }
        }
        if (! Schema::hasTable('marketing_leads')) {
            Schema::create('marketing_leads', function (Blueprint $t) {
                $t->id();
                $t->string('contact_id', 64)->unique('mkt_lead_contact_uq');
                $t->string('name', 190)->nullable();
                $t->string('company', 190)->nullable();
                $t->string('email', 190)->nullable();
                $t->string('phone', 40)->nullable();
                $t->string('state', 60)->nullable();
                $t->json('tags')->nullable();
                $t->boolean('dnd')->default(false);
                $t->timestamp('contact_updated_at')->nullable();
                $t->unsignedBigInteger('converted_customer_id')->nullable();
                $t->timestamp('dismissed_at')->nullable();
                $t->timestamps();
                $t->index(['converted_customer_id', 'dismissed_at'], 'mkt_lead_open_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_leads');
        foreach (['ghl_tags', 'ghl_dnd', 'ghl_stage', 'ghl_opportunity_value', 'ghl_contact_updated_at', 'contact_fields_updated_at', 'accept_marketing_contact_updates'] as $c) {
            if (Schema::hasColumn('customers', $c)) {
                Schema::table('customers', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
