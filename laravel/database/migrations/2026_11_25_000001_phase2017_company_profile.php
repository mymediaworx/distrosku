<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.20.17: company profile ("company defaults") printed on statements / invoices: address, phone, email. Additive, re-runnable. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }
        Schema::table('companies', function (Blueprint $t) {
            foreach (['address_line1', 'address_line2', 'city'] as $col) {
                if (! Schema::hasColumn('companies', $col)) {
                    $t->string($col)->nullable();
                }
            }
            foreach (['state' => 8, 'postal_code' => 16, 'phone' => 32] as $col => $len) {
                if (! Schema::hasColumn('companies', $col)) {
                    $t->string($col, $len)->nullable();
                }
            }
            if (! Schema::hasColumn('companies', 'email')) {
                $t->string('email')->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive only: columns stay
    }
};
