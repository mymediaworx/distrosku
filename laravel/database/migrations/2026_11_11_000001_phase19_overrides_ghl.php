<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.19.0: customer price overrides; GHL (white-label HighLevel) marketing add-on: contact link + opt-ins on customers,
 * sync log, transactional templates (all off by default). Re-run safe; short index names.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_price_overrides')) {
            Schema::create('customer_price_overrides', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete(); // null = all customers
                $t->decimal('price', 12, 4);
                $t->string('duration', 12)->default('ongoing');  // one_time|range|ongoing
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamp('used_at')->nullable();
                $t->unsignedBigInteger('used_sales_order_id')->nullable();
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['product_id', 'active'], 'cpo_prod_active');
                $t->index(['customer_id', 'product_id'], 'cpo_cust_prod');
            });
        }
        $cols = [
            'sales_order_lines' => ['price_override_id' => fn (Blueprint $t) => $t->unsignedBigInteger('price_override_id')->nullable()],
            'customers' => [
                'ghl_contact_id' => fn (Blueprint $t) => $t->string('ghl_contact_id', 64)->nullable(),
                'ghl_synced_at' => fn (Blueprint $t) => $t->timestamp('ghl_synced_at')->nullable(),
                'email_opt_in' => fn (Blueprint $t) => $t->boolean('email_opt_in')->default(false),
                'sms_opt_in' => fn (Blueprint $t) => $t->boolean('sms_opt_in')->default(false),
            ],
        ];
        foreach ($cols as $table => $list) {
            foreach ($list as $col => $fn) {
                if (! Schema::hasColumn($table, $col)) {
                    Schema::table($table, $fn);
                }
            }
        }
        if (! Schema::hasTable('ghl_sync_logs')) {
            Schema::create('ghl_sync_logs', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 20);                 // contact|event|notify|test
                $t->string('event', 40)->nullable();
                $t->string('subject_type', 120)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->string('status', 12)->default('queued'); // queued|ok|failed|skipped
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->string('summary', 500)->nullable();
                $t->string('error', 500)->nullable();
                $t->string('dedupe_key', 120)->nullable()->unique('ghl_log_dedupe_uq');
                $t->timestamps();
                $t->index(['status', 'created_at'], 'ghl_log_status_idx');
                $t->index(['subject_type', 'subject_id'], 'ghl_log_subject_idx');
            });
        }
        if (! Schema::hasTable('transactional_templates')) {
            Schema::create('transactional_templates', function (Blueprint $t) {
                $t->id();
                $t->string('key', 40);
                $t->string('channel', 10);  // email|sms
                $t->string('subject', 190)->nullable();
                $t->text('body');
                $t->boolean('enabled')->default(false);
                $t->timestamps();
                $t->unique(['key', 'channel'], 'tt_key_channel_uq');
            });
        }
        $now = now();
        $tpl = [
            ['order_confirmed', 'email', 'Order {order} confirmed', "Hi {customer},\n\nWe confirmed your order {order} for {total}. We will let you know when it is on the way.\n\n{company}"],
            ['order_confirmed', 'sms', null, '{company}: order {order} confirmed ({total}). Reply STOP to opt out.'],
            ['delivery_eta', 'email', 'Delivery scheduled for {date}', "Hi {customer},\n\nOrder {order} is scheduled for delivery on {date} (route {route}).\n\n{company}"],
            ['delivery_eta', 'sms', null, '{company}: order {order} is scheduled for delivery {date}. Reply STOP to opt out.'],
            ['invoice_emailed', 'email', 'Invoice {order} ({total})', "Hi {customer},\n\nYour invoice {order} for {total} is ready, due {due}. You can view and pay it in the customer portal.\n\n{company}"],
            ['invoice_emailed', 'sms', null, '{company}: invoice {order} for {total} is ready, due {due}. Reply STOP to opt out.'],
        ];
        foreach ($tpl as [$k, $c, $s, $b]) {
            if (! DB::table('transactional_templates')->where('key', $k)->where('channel', $c)->exists()) {
                DB::table('transactional_templates')->insert(['key' => $k, 'channel' => $c, 'subject' => $s, 'body' => $b, 'enabled' => false, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_templates');
        Schema::dropIfExists('ghl_sync_logs');
        Schema::dropIfExists('customer_price_overrides');
        foreach (['sales_order_lines' => ['price_override_id'], 'customers' => ['ghl_contact_id', 'ghl_synced_at', 'email_opt_in', 'sms_opt_in']] as $table => $list) {
            foreach ($list as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
