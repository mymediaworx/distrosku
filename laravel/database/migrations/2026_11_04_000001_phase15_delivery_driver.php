<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 (0.15.0): delivery days + route planning, driver phone screen (photo proof, notes, partial/refused),
 * regulated-product signatures (delivery, will-call, counter, van) and van sales. Re-run safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('users', [
            'landing_page' => fn (Blueprint $t) => $t->string('landing_page', 30)->nullable(), // null = dashboard | modules
        ]);
        $this->addColumns('customers', [
            'delivery_days' => fn (Blueprint $t) => $t->string('delivery_days', 40)->nullable(), // csv: mon,wed,fri
        ]);
        $this->addColumns('ship_tos', [
            'delivery_days' => fn (Blueprint $t) => $t->string('delivery_days', 40)->nullable(), // overrides customer's
            'route_name' => fn (Blueprint $t) => $t->string('route_name', 60)->nullable(),
            'route_sequence' => fn (Blueprint $t) => $t->unsignedInteger('route_sequence')->nullable(),
        ]);
        $this->addColumns('warehouses', [
            'is_van' => fn (Blueprint $t) => $t->boolean('is_van')->default(false),
            'van_driver_id' => fn (Blueprint $t) => $t->foreignId('van_driver_id')->nullable()->constrained('users', 'id', 'wh_van_driver_fk')->nullOnDelete(),
        ]);
        $this->addColumns('delivery_manifests', [
            'route_name' => fn (Blueprint $t) => $t->string('route_name', 60)->nullable(),
        ]);
        $this->addColumns('delivery_stops', [
            'notes' => fn (Blueprint $t) => $t->text('notes')->nullable(),
            'photo_path' => fn (Blueprint $t) => $t->string('photo_path')->nullable(),
            'status_by' => fn (Blueprint $t) => $t->foreignId('status_by')->nullable()->constrained('users', 'id', 'ds_status_by_fk')->nullOnDelete(),
            'latitude' => fn (Blueprint $t) => $t->decimal('latitude', 10, 7)->nullable(),
            'longitude' => fn (Blueprint $t) => $t->decimal('longitude', 10, 7)->nullable(),
        ]);
        if (! Schema::hasTable('delivery_signatures')) {
            Schema::create('delivery_signatures', function (Blueprint $t) {
                $t->id();
                $t->foreignId('sales_order_id')->constrained('sales_orders', 'id', 'dsig_so_fk')->cascadeOnDelete();
                $t->foreignId('delivery_stop_id')->nullable()->constrained('delivery_stops', 'id', 'dsig_stop_fk')->nullOnDelete();
                $t->string('context', 20); // delivery | pickup | counter | van
                $t->string('signer_name', 120);
                $t->string('image_path');
                $t->text('acknowledgments'); // JSON array of the exact texts the signer accepted
                $t->boolean('account_charge')->default(false);
                $t->decimal('order_total', 14, 4)->nullable();
                $t->foreignId('user_id')->nullable()->constrained('users', 'id', 'dsig_user_fk')->nullOnDelete();
                $t->string('ip', 45)->nullable();
                $t->decimal('latitude', 10, 7)->nullable();
                $t->decimal('longitude', 10, 7)->nullable();
                $t->timestamp('signed_at');
                $t->timestamps();
            });
        }
        $this->ensureIndex('delivery_signatures', ['sales_order_id', 'signed_at'], 'dsig_so_signed_idx');
        $this->ensureIndex('delivery_stops', ['delivery_manifest_id', 'sequence'], 'ds_manifest_seq_idx');
        $this->ensureIndex('delivery_manifests', ['driver_id', 'delivery_date'], 'dm_driver_date_idx');
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_signatures');
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
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($defs as $col => $def) {
            if (! Schema::hasColumn($table, $col)) {
                Schema::table($table, fn (Blueprint $t) => $def($t));
            }
        }
    }
};
