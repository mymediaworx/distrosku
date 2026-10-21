<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 accounting: AP (vendor bills, 3-way match, payments), bank accounts + reconciliation,
 * fiscal periods (close / reopen with audit) and dunning (draft notices only; nothing is ever emailed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->foreignId('account_id')->constrained('accounts'); // GL cash account
                $t->string('bank_name')->nullable();
                $t->string('last4', 4)->nullable();
                $t->date('cleared_through')->nullable(); // GL lines on or before this date count as already cleared (pre-reconciliation history)
                $t->boolean('is_default')->default(false);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('vendor_bills')) {
            Schema::create('vendor_bills', function (Blueprint $t) {
                $t->id();
                $t->string('number', 30)->unique();               // internal
                $t->foreignId('vendor_id')->constrained();
                $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
                $t->string('vendor_invoice')->nullable();         // vendor's invoice number
                $t->date('bill_date');
                $t->date('due_date');
                $t->string('status', 20)->default('draft');       // draft, posted, partial, paid, void
                $t->string('match_status', 20)->default('none');  // none (standalone), matched, variance
                $t->json('match_notes')->nullable();
                $t->decimal('total', 14, 4)->default(0);
                $t->decimal('amount_paid', 14, 4)->default(0);
                $t->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
                $t->foreignId('variance_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('posted_at')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->unique(['vendor_id', 'vendor_invoice']);
            });
            Schema::create('vendor_bill_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('vendor_bill_id')->constrained()->cascadeOnDelete();
                $t->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $t->string('account_code', 10)->nullable();       // standalone / extra lines (freight 6100, expenses 6000...)
                $t->string('description')->nullable();
                $t->decimal('qty', 12, 4)->default(1);
                $t->decimal('unit_cost', 12, 4)->default(0);
                $t->decimal('amount', 14, 4)->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('purchase_order_lines', 'qty_billed')) {
            Schema::table('purchase_order_lines', function (Blueprint $t) {
                $t->decimal('qty_billed', 12, 4)->default(0);
                // Quantity received before phase 8: those receipts credited AP (2000) directly, later ones credit 2050 Received Not Billed.
                $t->decimal('qty_received_legacy', 12, 4)->default(0);
            });
            DB::table('purchase_order_lines')->update(['qty_received_legacy' => DB::raw('qty_received')]);
        }

        if (! Schema::hasTable('vendor_payments')) {
            Schema::create('vendor_payments', function (Blueprint $t) {
                $t->id();
                $t->string('number', 30)->unique();
                $t->foreignId('vendor_id')->constrained();
                $t->foreignId('bank_account_id')->constrained();
                $t->date('paid_on');
                $t->string('method', 20)->default('check');       // check, ach, card, cash, wire
                $t->string('reference')->nullable();
                $t->decimal('amount', 14, 4);
                $t->string('status', 20)->default('posted');      // posted, void
                $t->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
                $t->timestamps();
            });
            Schema::create('vendor_payment_allocations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('vendor_payment_id')->constrained()->cascadeOnDelete();
                $t->foreignId('vendor_bill_id')->constrained();
                $t->decimal('amount', 14, 4);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
                $t->date('statement_date');
                $t->decimal('opening_balance', 14, 4)->default(0);
                $t->decimal('closing_balance', 14, 4)->default(0);
                $t->string('filename')->nullable();
                $t->string('status', 20)->default('open');        // open, reconciled
                $t->timestamp('reconciled_at')->nullable();
                $t->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
                $t->json('summary')->nullable();
                $t->timestamps();
            });
            Schema::create('bank_statement_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
                $t->date('txn_date');
                $t->string('description')->nullable();
                $t->string('reference')->nullable();
                $t->decimal('amount', 14, 4);                     // + deposit, - withdrawal
                $t->string('status', 20)->default('unmatched');   // unmatched, matched, adjusted
                $t->foreignId('journal_line_id')->nullable()->unique()->constrained('journal_lines')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('fiscal_periods')) {
            Schema::create('fiscal_periods', function (Blueprint $t) {
                $t->id();
                $t->string('period', 7)->unique();                // YYYY-MM
                $t->date('starts_on');
                $t->date('ends_on');
                $t->string('status', 20)->default('open');        // open, closed
                $t->timestamp('closed_at')->nullable();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
            Schema::create('period_audits', function (Blueprint $t) {
                $t->id();
                $t->foreignId('fiscal_period_id')->constrained()->cascadeOnDelete();
                $t->string('action', 20);                         // close, reopen
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->text('reason')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('dunning_batches')) {
            Schema::create('dunning_batches', function (Blueprint $t) {
                $t->id();
                $t->date('as_of');
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->unsignedInteger('notice_count')->default(0);
                $t->decimal('total_overdue', 14, 4)->default(0);
                $t->timestamps();
            });
            Schema::create('dunning_notices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('dunning_batch_id')->constrained()->cascadeOnDelete();
                $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $t->unsignedTinyInteger('level');
                $t->unsignedInteger('max_days_overdue');
                $t->decimal('overdue_amount', 14, 4);
                $t->decimal('balance', 14, 4);
                $t->string('to_email')->nullable();
                $t->string('subject');
                $t->text('body');
                $t->string('status', 20)->default('draft');       // draft, reviewed, dismissed (never "sent": the ERP does not send)
                $t->json('invoices')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['dunning_notices', 'dunning_batches', 'period_audits', 'fiscal_periods', 'bank_statement_lines', 'bank_statements',
            'vendor_payment_allocations', 'vendor_payments', 'vendor_bill_lines', 'vendor_bills', 'bank_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('purchase_order_lines', 'qty_billed')) {
            Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn(['qty_billed', 'qty_received_legacy']));
        }
    }
};
