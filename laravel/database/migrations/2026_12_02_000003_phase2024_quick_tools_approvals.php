<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#45). Additive and re-runnable. New SKUs from the Quick Tools page wait for an owner/admin:
 * approval_status null = normal product (every existing row), 'pending' = waiting, 'rejected' = turned down (archived).
 * Who asked (by employee PIN) and who decided are kept on the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        if (! Schema::hasColumn('products', 'approval_status')) {
            Schema::table('products', function (Blueprint $t) {
                $t->string('approval_status', 12)->nullable()->index('products_approval_status_idx');
            });
        }
        foreach (['requested_by' => 'prod_requested_by_fk', 'approved_by' => 'prod_approved_by_fk'] as $col => $fk) {
            if (! Schema::hasColumn('products', $col)) {
                Schema::table('products', fn (Blueprint $t) => $t->foreignId($col)->nullable()->constrained('users', 'id', $fk)->nullOnDelete());
            }
        }
        if (! Schema::hasColumn('products', 'approved_at')) {
            Schema::table('products', fn (Blueprint $t) => $t->timestamp('approved_at')->nullable());
        }
        if (! Schema::hasColumn('products', 'approval_note')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('approval_note', 255)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['requested_by' => 'prod_requested_by_fk', 'approved_by' => 'prod_approved_by_fk'] as $col => $fk) {
            if (Schema::hasColumn('products', $col)) {
                Schema::table('products', function (Blueprint $t) use ($col, $fk) {
                    $t->dropForeign($fk);
                    $t->dropColumn($col);
                });
            }
        }
        foreach (['approval_status', 'approved_at', 'approval_note'] as $col) {
            if (Schema::hasColumn('products', $col)) {
                Schema::table('products', function (Blueprint $t) use ($col) {
                    if ($col === 'approval_status') {
                        $t->dropIndex('products_approval_status_idx');
                    }
                    $t->dropColumn($col);
                });
            }
        }
    }
};
