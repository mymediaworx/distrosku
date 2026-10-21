<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Re-runnable over a partly applied state (0.13.1): tables, columns and indexes guarded; explicit index names.
 *  Phase 12: PACT Act registrations + monthly per-state reports, MSA state directory lists, carriers and carton tracking. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pact_registrations')) {
            Schema::create('pact_registrations', function (Blueprint $t) {
                $t->id();
                $t->string('jurisdiction', 10);                  // US = ATF (Attorney General), else state code (KY, IN, ...)
                $t->string('legal_name');
                $t->string('trade_name')->nullable();
                $t->string('principal_address')->nullable();
                $t->text('other_places')->nullable();            // other places of business, one per line, with phone
                $t->string('phone', 40)->nullable();
                $t->string('email')->nullable();
                $t->string('websites')->nullable();
                $t->string('agent_name')->nullable();            // agent in the state authorised to accept service
                $t->string('agent_address')->nullable();
                $t->string('agent_phone', 40)->nullable();
                $t->string('administrator')->nullable();         // state tobacco tax administrator (where reports go)
                $t->string('administrator_contact')->nullable(); // address / email / portal
                $t->string('reference')->nullable();             // ATF / state registration or acknowledgement number
                $t->date('filed_on')->nullable();
                $t->date('expires_on')->nullable();
                $t->boolean('active')->default(true);
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('carriers')) {
            Schema::create('carriers', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20);
                $t->string('name');
                $t->string('address')->nullable();               // PACT report: name, address, phone of the person delivering
                $t->string('phone', 40)->nullable();
                $t->string('tracking_url')->nullable();          // with {tracking}
                $t->boolean('is_postal')->default(false);        // USPS: cigarettes / smokeless are nonmailable (18 U.S.C. 1716E)
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        $this->ensureIndex('carriers', ['code'], 'carriers_code_unique', true);
        $this->addColumns('packages', [
            'carrier_id' => fn (Blueprint $t) => $t->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete(),
            'service' => fn (Blueprint $t) => $t->string('service', 40)->nullable(),
            'tracking_number' => fn (Blueprint $t) => $t->string('tracking_number', 60)->nullable(),
            'shipped_at' => fn (Blueprint $t) => $t->timestamp('shipped_at')->nullable(),
            'label_printed_at' => fn (Blueprint $t) => $t->timestamp('label_printed_at')->nullable(),
        ]);
        $this->ensureIndex('packages', ['tracking_number'], 'packages_tracking_number_index');
        if (! Schema::hasTable('pact_reports')) {
            Schema::create('pact_reports', function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('period', 7);                         // YYYY-MM
                $t->string('status', 10)->default('draft');      // draft, filed
                $t->date('due_on');
                $t->json('totals')->nullable();
                $t->timestamp('generated_at')->nullable();
                $t->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('filed_at')->nullable();
                $t->string('filed_reference')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('pact_reports', ['state', 'period'], 'pact_reports_state_period_unique', true);
        if (! Schema::hasTable('pact_report_lines')) {
            Schema::create('pact_report_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('pact_report_id')->constrained()->cascadeOnDelete();
                $t->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
                $t->date('shipped_on');
                $t->string('invoice', 40);
                $t->string('customer');                          // recipient name
                $t->string('license')->nullable();
                $t->string('address')->nullable();
                $t->string('city')->nullable();
                $t->string('zip', 10)->nullable();
                $t->string('category', 12);                      // cigarette, ryo, smokeless, ends
                $t->string('brand')->nullable();
                $t->string('sku', 60);
                $t->string('description')->nullable();
                $t->decimal('qty', 12, 4);
                $t->string('unit', 20)->nullable();
                $t->decimal('sticks_or_units', 14, 4)->nullable(); // qty x items per unit (sticks / cans / oz as set on the product)
                $t->string('carrier')->nullable();
                $t->string('carrier_address')->nullable();
                $t->string('carrier_phone', 40)->nullable();
                $t->string('tracking')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('msa_directory_imports')) {
            Schema::create('msa_directory_imports', function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->date('effective_on');
                $t->string('filename')->nullable();
                $t->string('source')->nullable();                // e.g. URL / publication date of the state's directory
                $t->unsignedInteger('rows')->default(0);
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('msa_directory_entries')) {
            Schema::create('msa_directory_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('msa_directory_import_id')->nullable()->constrained()->cascadeOnDelete();
                $t->string('state', 2);
                $t->string('manufacturer');
                $t->string('brand_family');
                $t->string('brand_key');                         // normalised for matching
                $t->string('product_type', 12)->default('cigarette'); // cigarette, ryo
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('msa_directory_entries', ['state', 'brand_key'], 'msa_directory_entries_state_brand_key_index');
        if (! Schema::hasColumn('products', 'msa_brand_family')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('msa_brand_family')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['msa_directory_entries', 'msa_directory_imports', 'pact_report_lines', 'pact_reports'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('packages', 'carrier_id')) {
            Schema::table('packages', fn (Blueprint $t) => $t->dropConstrainedForeignId('carrier_id'));
        }
        if (Schema::hasIndex('packages', 'packages_tracking_number_index')) {
            Schema::table('packages', fn (Blueprint $t) => $t->dropIndex('packages_tracking_number_index'));
        }
        foreach (['service', 'tracking_number', 'shipped_at', 'label_printed_at'] as $c) {
            if (Schema::hasColumn('packages', $c)) {
                Schema::table('packages', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        Schema::dropIfExists('carriers');
        Schema::dropIfExists('pact_registrations');
        if (Schema::hasColumn('products', 'msa_brand_family')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('msa_brand_family'));
        }
    }

    /** Re-run safety (MySQL DDL is not transactional): add an index only if none with this name exists yet. */
    private function ensureIndex(string $table, array $cols, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
    }

    /** Add each missing column on its own, so a half-applied ALTER is completed rather than skipped. */
    private function addColumns(string $table, array $defs): void
    {
        foreach ($defs as $col => $fn) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $col)) {
                Schema::table($table, $fn);
            }
        }
    }
};
