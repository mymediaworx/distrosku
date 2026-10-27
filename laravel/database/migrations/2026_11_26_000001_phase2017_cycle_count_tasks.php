<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.20.17: cycle count plan. One row per product to count on a plan date. Counts themselves post through the existing count path. Additive, re-runnable. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cycle_count_tasks')) {
            return;
        }
        Schema::create('cycle_count_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->date('task_date');
            $t->string('reason', 40);              // abc_a / high_risk / discrepancy / not_recent / abc_b / abc_c
            $t->string('abc_class', 1)->nullable(); // A / B / C velocity-value class when the task was planned
            $t->unsignedInteger('priority')->default(0); // higher first
            $t->string('status', 16)->default('open');  // open | counting | counted | skipped
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('inventory_count_id')->nullable()->constrained('inventory_counts')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['warehouse_id', 'product_id', 'task_date']);
            $t->index(['task_date', 'status']);
        });
    }

    public function down(): void
    {
        // additive only: keep the task history
    }
};
