<?php

use App\Support\CustomerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.3: portal sections + fixed customer types.
 *  - products: portal_promo, portal_new, portal_new_until, portal_featured, portal_sort.
 *  - customers: type mapped to the fixed list; original kept in type_legacy (no data loss).
 * Re-runnable: columns checked first; mapping only touches rows whose type is not already a valid option.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'portal_promo' => fn (Blueprint $t) => $t->boolean('portal_promo')->default(false),
            'portal_new' => fn (Blueprint $t) => $t->boolean('portal_new')->default(false),
            'portal_new_until' => fn (Blueprint $t) => $t->date('portal_new_until')->nullable(),
            'portal_featured' => fn (Blueprint $t) => $t->boolean('portal_featured')->default(false),
            'portal_sort' => fn (Blueprint $t) => $t->integer('portal_sort')->default(0),
        ];
        foreach ($cols as $c => $fn) {
            if (! Schema::hasColumn('products', $c)) {
                Schema::table('products', $fn);
            }
        }
        if (! Schema::hasColumn('customers', 'type_legacy')) {
            Schema::table('customers', fn (Blueprint $t) => $t->string('type_legacy', 60)->nullable());
        }
        DB::table('customers')->select('id', 'type')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                if (! array_key_exists((string) $r->type, CustomerType::OPTIONS)) {
                    DB::table('customers')->where('id', $r->id)->update(['type_legacy' => $r->type, 'type' => CustomerType::map($r->type)]);
                }
            }
        });
    }

    public function down(): void {}
};
