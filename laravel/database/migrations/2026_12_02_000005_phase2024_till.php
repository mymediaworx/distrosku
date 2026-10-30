<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#44). Additive and re-runnable. Counter cash drawer: a till session per register (open with a float, close with
 * a count), paid-in / paid-out / safe drops, and the variance (over/short). POS sales made while a till is open are linked
 * to it. Two GL accounts are added: 6500 Paid-outs (cash taken from the drawer for expenses) and 6550 Cash Over/Short.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('till_sessions')) {
            Schema::create('till_sessions', function (Blueprint $t) {
                $t->id();
                $t->string('register', 80);
                $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('opened_at');
                $t->decimal('opening_float', 12, 2)->default(0);
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('closed_at')->nullable();
                $t->decimal('expected_cash', 12, 2)->nullable();
                $t->decimal('counted_cash', 12, 2)->nullable();
                $t->decimal('variance', 12, 2)->nullable();
                $t->json('count_detail')->nullable();
                $t->string('status', 10)->default('open')->index();
                $t->string('notes', 255)->nullable();
                $t->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
                $t->timestamps();
                $t->index(['register', 'status'], 'till_reg_status_idx');
            });
        }
        if (! Schema::hasTable('till_movements')) {
            Schema::create('till_movements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('till_session_id')->constrained()->cascadeOnDelete();
                $t->string('type', 12); // paid_in | paid_out | drop
                $t->decimal('amount', 12, 2);
                $t->string('reason', 255);
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('sales_orders') && ! Schema::hasColumn('sales_orders', 'till_session_id')) {
            Schema::table('sales_orders', fn (Blueprint $t) => $t->foreignId('till_session_id')->nullable()->constrained('till_sessions', 'id', 'so_till_fk')->nullOnDelete());
        }
        if (Schema::hasTable('accounts')) {
            foreach ([['6500', 'Paid-outs (from the cash drawer)', 'expense'], ['6550', 'Cash Over/Short', 'expense']] as [$code, $name, $type]) {
                if (! DB::table('accounts')->where('code', $code)->exists()) {
                    DB::table('accounts')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'till_session_id')) {
            Schema::table('sales_orders', function (Blueprint $t) {
                $t->dropForeign('so_till_fk');
                $t->dropColumn('till_session_id');
            });
        }
        Schema::dropIfExists('till_movements');
        Schema::dropIfExists('till_sessions');
    }
};
