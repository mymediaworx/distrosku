<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.1: buydown/rebate GL + credit-memo reversals + manufacturer receipts. Runs on top of 0.14.0; re-run safe.
 * Accounts 1250 Manufacturer Rebates Receivable (asset) and 5050 Manufacturer Rebates & Buydowns (contra-COGS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            foreach ([['1250', 'Manufacturer Rebates Receivable', 'asset'], ['5050', 'Manufacturer Rebates & Buydowns (contra-COGS)', 'expense']] as [$c, $n, $t]) {
                if (! DB::table('accounts')->where('code', $c)->exists()) {
                    DB::table('accounts')->insert(['code' => $c, 'name' => $n, 'type' => $t, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        // POS PIN quick login: bcrypt hash + keyed HMAC lookup (unique per install) so a PIN can be found without storing it.
        $this->addColumns('users', [
            'pos_pin_hash' => fn (Blueprint $t) => $t->string('pos_pin_hash')->nullable(),
            'pos_pin_lookup' => fn (Blueprint $t) => $t->string('pos_pin_lookup', 64)->nullable(),
            'pos_pin_set_at' => fn (Blueprint $t) => $t->timestamp('pos_pin_set_at')->nullable(),
        ]);
        $this->ensureIndex('users', ['pos_pin_lookup'], 'users_pos_pin_uq', true);
        if (! Schema::hasTable('pos_pin_logins')) {
            Schema::create('pos_pin_logins', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained('users', 'id', 'ppl_user_fk')->nullOnDelete();
                $t->string('event', 20); // success | failed | locked | lock_screen | pin_set
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('pos_pin_logins', ['user_id', 'created_at'], 'ppl_user_created_idx');

        if (! Schema::hasTable('buydown_accruals')) {
            return; // 0.14.0 migration not applied yet; it runs first
        }
        $this->addColumns('buydown_accruals', [
            'kind' => fn (Blueprint $t) => $t->string('kind', 10)->default('accrual'), // accrual | reversal
            'dedupe_key' => fn (Blueprint $t) => $t->string('dedupe_key', 60)->nullable(),
            'reverses_id' => fn (Blueprint $t) => $t->foreignId('reverses_id')->nullable()->constrained('buydown_accruals', 'id', 'bda_reverses_fk')->nullOnDelete(),
            'credit_memo_id' => fn (Blueprint $t) => $t->foreignId('credit_memo_id')->nullable()->constrained('credit_memos', 'id', 'bda_cmemo_fk')->nullOnDelete(),
            'credit_memo_line_id' => fn (Blueprint $t) => $t->foreignId('credit_memo_line_id')->nullable()->constrained('credit_memo_lines', 'id', 'bda_cmline_fk')->nullOnDelete(),
            'journal_id' => fn (Blueprint $t) => $t->foreignId('journal_id')->nullable()->constrained('journals', 'id', 'bda_journal_fk')->nullOnDelete(),
            'paid_at' => fn (Blueprint $t) => $t->date('paid_at')->nullable(),
            'payment_journal_id' => fn (Blueprint $t) => $t->foreignId('payment_journal_id')->nullable()->constrained('journals', 'id', 'bda_payjrnl_fk')->nullOnDelete(),
        ]);
        foreach (DB::table('buydown_accruals')->whereNull('dedupe_key')->get(['id', 'buydown_id', 'sales_order_line_id']) as $r) {
            DB::table('buydown_accruals')->where('id', $r->id)->update(['dedupe_key' => "a:{$r->buydown_id}:{$r->sales_order_line_id}"]);
        }
        // Reversals share buydown + line, so the 0.14.0 unique key moves to dedupe_key. Keep a plain index for the buydown FK first.
        $this->ensureIndex('buydown_accruals', ['buydown_id'], 'bda_buydown_idx');
        $this->ensureIndex('buydown_accruals', ['dedupe_key'], 'bda_dedupe_uq', true);
        if (Schema::hasIndex('buydown_accruals', 'bda_buydown_line_uq')) {
            Schema::table('buydown_accruals', fn (Blueprint $t) => $t->dropUnique('bda_buydown_line_uq'));
        }
        $this->ensureIndex('buydown_accruals', ['sales_order_line_id', 'kind'], 'bda_line_kind_idx');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_pin_logins');
        if (Schema::hasIndex('users', 'users_pos_pin_uq')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropUnique('users_pos_pin_uq'));
        }
        foreach (['pos_pin_hash', 'pos_pin_lookup', 'pos_pin_set_at'] as $c) {
            if (Schema::hasColumn('users', $c)) {
                Schema::table('users', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        if (! Schema::hasTable('buydown_accruals')) {
            return;
        }
        DB::table('buydown_accruals')->where('kind', 'reversal')->delete();
        $this->ensureIndex('buydown_accruals', ['buydown_id', 'sales_order_line_id'], 'bda_buydown_line_uq', true);
        foreach (['bda_dedupe_uq' => true] as $ix => $u) { // bda_line_kind_idx / bda_buydown_idx stay: MySQL may use them for the FKs
            if (Schema::hasIndex('buydown_accruals', $ix)) {
                Schema::table('buydown_accruals', fn (Blueprint $t) => $u ? $t->dropUnique($ix) : $t->dropIndex($ix));
            }
        }
        foreach (['reverses_id' => 'bda_reverses_fk', 'credit_memo_id' => 'bda_cmemo_fk', 'credit_memo_line_id' => 'bda_cmline_fk', 'journal_id' => 'bda_journal_fk', 'payment_journal_id' => 'bda_payjrnl_fk'] as $c => $fk) {
            if (Schema::hasColumn('buydown_accruals', $c)) {
                Schema::table('buydown_accruals', function (Blueprint $t) use ($c, $fk) { $t->dropForeign($fk); $t->dropColumn($c); });
            }
        }
        foreach (['kind', 'dedupe_key', 'paid_at'] as $c) {
            if (Schema::hasColumn('buydown_accruals', $c)) {
                Schema::table('buydown_accruals', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }

    private function ensureIndex(string $table, array $cols, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
    }

    private function addColumns(string $table, array $defs): void
    {
        foreach ($defs as $col => $fn) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $col)) {
                Schema::table($table, $fn);
            }
        }
    }
};
