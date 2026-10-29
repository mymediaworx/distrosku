<?php

/**
 * 0.20.13:
 * - customer_licenses.site_id (KY ABC Site ID); move numeric KY Site IDs out of `number`.
 * - customers.dba nullable trading name.
 * - customer_contacts: email nullable; is_portal_contact (default false); backfill portal users.
 * - beginning_balance (+ applied / audit) decimal(12,4) → decimal(12,2) with ROUND.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_licenses', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_licenses', 'site_id')) {
                $t->string('site_id', 40)->nullable()->after('type');
            }
        });
        Schema::table('customer_licenses', function (Blueprint $t) {
            $t->string('number')->nullable()->change();
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE customer_licenses SET site_id = number, number = NULL
                WHERE UPPER(state) = 'KY' AND site_id IS NULL
                  AND number IS NOT NULL AND number REGEXP '^[0-9]{1,10}$'");
        } else {
            foreach (DB::table('customer_licenses')->whereRaw("UPPER(state) = 'KY'")->whereNull('site_id')->whereNotNull('number')->get(['id', 'number']) as $r) {
                if (preg_match('/^[0-9]{1,10}$/', (string) $r->number)) {
                    DB::table('customer_licenses')->where('id', $r->id)->update(['site_id' => $r->number, 'number' => null]);
                }
            }
        }
        DB::table('customer_licenses')->where('site_id', '')->update(['site_id' => null]);
        DB::table('customer_licenses')->where('number', '')->update(['number' => null]);

        $indexes = collect(Schema::getIndexes('customer_licenses'))->pluck('name')->all();
        Schema::table('customer_licenses', function (Blueprint $t) use ($indexes) {
            if (! in_array('customer_licenses_state_site_id_unique', $indexes, true)) {
                $t->unique(['state', 'site_id'], 'customer_licenses_state_site_id_unique');
            }
            if (! in_array('customer_licenses_state_number_unique', $indexes, true)) {
                $t->unique(['state', 'number'], 'customer_licenses_state_number_unique');
            }
        });

        Schema::table('customers', function (Blueprint $t) {
            if (! Schema::hasColumn('customers', 'dba')) {
                $t->string('dba')->nullable()->after('name');
            }
        });

        Schema::table('customer_contacts', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_contacts', 'is_portal_contact')) {
                $t->boolean('is_portal_contact')->default(false)->after('active');
            }
        });
        Schema::table('customer_contacts', function (Blueprint $t) {
            $t->string('email')->nullable()->change();
        });
        // Existing portal logins / open invites keep portal access.
        DB::table('customer_contacts')->where(function ($q) {
            $q->whereNotNull('password')
                ->orWhereNotNull('setup_token_hash')
                ->orWhereNotNull('last_login_at');
        })->update(['is_portal_contact' => true]);
        DB::table('customer_contacts')->where('email', '')->update(['email' => null]);

        // Beginning balance was decimal(12,4); money UI is 2 places. Round then shrink.
        // beginning_balance_applied and audit snapshots follow (same money amounts; nothing needs 4 places).
        if (Schema::hasColumn('customers', 'beginning_balance')) {
            DB::table('customers')->update([
                'beginning_balance' => DB::raw('ROUND(beginning_balance, 2)'),
                'beginning_balance_applied' => DB::raw('ROUND(beginning_balance_applied, 2)'),
            ]);
            Schema::table('customers', function (Blueprint $t) {
                $t->decimal('beginning_balance', 12, 2)->default(0)->change();
                $t->decimal('beginning_balance_applied', 12, 2)->default(0)->change();
            });
        }
        if (Schema::hasTable('customer_balance_audits')) {
            DB::table('customer_balance_audits')->update([
                'old_balance' => DB::raw('ROUND(old_balance, 2)'),
                'new_balance' => DB::raw('ROUND(new_balance, 2)'),
            ]);
            Schema::table('customer_balance_audits', function (Blueprint $t) {
                $t->decimal('old_balance', 12, 2)->change();
                $t->decimal('new_balance', 12, 2)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'beginning_balance')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->decimal('beginning_balance', 12, 4)->default(0)->change();
                $t->decimal('beginning_balance_applied', 12, 4)->default(0)->change();
            });
        }
        if (Schema::hasTable('customer_balance_audits')) {
            Schema::table('customer_balance_audits', function (Blueprint $t) {
                $t->decimal('old_balance', 12, 4)->change();
                $t->decimal('new_balance', 12, 4)->change();
            });
        }

        $indexes = collect(Schema::getIndexes('customer_licenses'))->pluck('name')->all();
        Schema::table('customer_licenses', function (Blueprint $t) use ($indexes) {
            if (in_array('customer_licenses_state_site_id_unique', $indexes, true)) {
                $t->dropUnique('customer_licenses_state_site_id_unique');
            }
            if (in_array('customer_licenses_state_number_unique', $indexes, true)) {
                $t->dropUnique('customer_licenses_state_number_unique');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE customer_licenses SET number = site_id WHERE site_id IS NOT NULL AND (number IS NULL OR number = '')");
        } else {
            foreach (DB::table('customer_licenses')->whereNotNull('site_id')->get(['id', 'number', 'site_id']) as $r) {
                if ($r->number === null || $r->number === '') {
                    DB::table('customer_licenses')->where('id', $r->id)->update(['number' => $r->site_id]);
                }
            }
        }
        DB::table('customer_licenses')->whereNull('number')->update(['number' => 'UNKNOWN']);
        Schema::table('customer_licenses', function (Blueprint $t) {
            if (Schema::hasColumn('customer_licenses', 'site_id')) {
                $t->dropColumn('site_id');
            }
            $t->string('number')->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $t) {
            if (Schema::hasColumn('customers', 'dba')) {
                $t->dropColumn('dba');
            }
        });

        $i = 0;
        foreach (DB::table('customer_contacts')->whereNull('email')->orWhere('email', '')->get(['id']) as $r) {
            DB::table('customer_contacts')->where('id', $r->id)->update(['email' => 'migrated-contact-'.(++$i).'@invalid.local']);
        }
        Schema::table('customer_contacts', function (Blueprint $t) {
            if (Schema::hasColumn('customer_contacts', 'is_portal_contact')) {
                $t->dropColumn('is_portal_contact');
            }
            $t->string('email')->nullable(false)->change();
        });
    }
};
