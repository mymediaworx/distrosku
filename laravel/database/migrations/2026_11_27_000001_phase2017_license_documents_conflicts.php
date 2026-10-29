<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.17 licenses: document attachments + research note on each license, and a review queue of license/account conflicts.
 * Additive and re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_licenses', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_licenses', 'documents')) {
                $t->json('documents')->nullable();
            }
            if (! Schema::hasColumn('customer_licenses', 'research_note')) {
                $t->text('research_note')->nullable();
            }
        });

        if (! Schema::hasTable('license_conflicts')) {
            Schema::create('license_conflicts', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 30); // no_proof | expired | duplicate_tax_id | duplicate_name | missing_license
                $t->string('fingerprint', 150)->unique();
                $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('license_id')->nullable()->constrained('customer_licenses')->nullOnDelete();
                $t->json('related_customer_ids')->nullable();
                $t->string('summary', 500);
                $t->string('status', 16)->default('pending'); // pending | approved | edited | skipped | cleared
                $t->text('note')->nullable();
                $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('resolved_at')->nullable();
                $t->timestamps();
                $t->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('license_conflicts');
        Schema::table('customer_licenses', function (Blueprint $t) {
            foreach (['documents', 'research_note'] as $c) {
                if (Schema::hasColumn('customer_licenses', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
