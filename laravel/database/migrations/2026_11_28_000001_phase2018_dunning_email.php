<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.18 automatic dunning email. Additive and re-runnable.
 * - customers.dunning_email_optout: per-customer opt-out (the notice goes to the print list instead).
 * - customer_contacts.is_billing_contact: preferred recipient(s) for dunning email.
 * - dunning_notices: email result columns (status, when, to, attempts, error) and printed_at for the print list.
 * Mail credentials are NOT stored here (they live in the encrypted settings table or .env).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'dunning_email_optout')) {
            Schema::table('customers', fn (Blueprint $t) => $t->boolean('dunning_email_optout')->default(false));
        }
        if (! Schema::hasColumn('customer_contacts', 'is_billing_contact')) {
            Schema::table('customer_contacts', fn (Blueprint $t) => $t->boolean('is_billing_contact')->default(false));
        }
        Schema::table('dunning_notices', function (Blueprint $t) {
            if (! Schema::hasColumn('dunning_notices', 'email_status')) {
                $t->string('email_status', 20)->nullable()->index(); // sent, failed, no_email, opted_out, duplicate, not_due
            }
            if (! Schema::hasColumn('dunning_notices', 'emailed_at')) {
                $t->timestamp('emailed_at')->nullable();
            }
            if (! Schema::hasColumn('dunning_notices', 'email_to')) {
                $t->text('email_to')->nullable();
            }
            if (! Schema::hasColumn('dunning_notices', 'email_attempts')) {
                $t->unsignedTinyInteger('email_attempts')->default(0);
            }
            if (! Schema::hasColumn('dunning_notices', 'email_error')) {
                $t->string('email_error', 500)->nullable();
            }
            if (! Schema::hasColumn('dunning_notices', 'printed_at')) {
                $t->timestamp('printed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['email_status', 'emailed_at', 'email_to', 'email_attempts', 'email_error', 'printed_at'] as $c) {
            if (Schema::hasColumn('dunning_notices', $c)) {
                if ($c === 'email_status') {
                    Schema::table('dunning_notices', fn (Blueprint $t) => $t->dropIndex(['email_status']));
                }
                Schema::table('dunning_notices', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        if (Schema::hasColumn('customer_contacts', 'is_billing_contact')) {
            Schema::table('customer_contacts', fn (Blueprint $t) => $t->dropColumn('is_billing_contact'));
        }
        if (Schema::hasColumn('customers', 'dunning_email_optout')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('dunning_email_optout'));
        }
    }
};
