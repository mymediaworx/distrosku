<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 17 (0.17.0): spreadsheet imports, vendor price files, vendor item numbers, scheduled costs. Re-run safe; short key names. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_mappings')) {
            Schema::create('import_mappings', function (Blueprint $t) {
                $t->id();
                $t->string('type', 30);
                $t->foreignId('vendor_id')->nullable()->constrained('vendors', 'id', 'im_vendor_fk')->cascadeOnDelete();
                $t->string('name', 80);
                $t->text('mapping'); // JSON field => header
                $t->foreignId('user_id')->nullable()->constrained('users', 'id', 'im_user_fk')->nullOnDelete();
                $t->timestamps();
            });
        }
        $this->ensureIndex('import_mappings', ['type', 'vendor_id', 'name'], 'im_type_vendor_name_uq', true);
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function (Blueprint $t) {
                $t->id();
                $t->string('type', 30);
                $t->string('status', 20)->default('uploaded'); // uploaded|validating|validated|committing|done|failed|cancelled
                $t->string('original_name', 190);
                $t->string('file_path');
                $t->text('headers')->nullable();  // JSON
                $t->text('mapping')->nullable();  // JSON field => header
                $t->foreignId('vendor_id')->nullable()->constrained('vendors', 'id', 'ib_vendor_fk')->nullOnDelete();
                $t->date('effective_on')->nullable();
                $t->unsignedInteger('total_rows')->default(0);
                $t->unsignedInteger('cursor')->default(0); // last row_no processed in the current stage
                $t->unsignedInteger('valid_rows')->default(0);
                $t->unsignedInteger('error_rows')->default(0);
                $t->unsignedInteger('created_count')->default(0);
                $t->unsignedInteger('updated_count')->default(0);
                $t->unsignedInteger('unchanged_count')->default(0);
                $t->string('message')->nullable();
                $t->foreignId('user_id')->nullable()->constrained('users', 'id', 'ib_user_fk')->nullOnDelete();
                $t->timestamp('validated_at')->nullable();
                $t->timestamp('committed_at')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('import_batches', ['type', 'created_at'], 'ib_type_created_idx');
        if (! Schema::hasTable('import_rows')) {
            Schema::create('import_rows', function (Blueprint $t) {
                $t->id();
                $t->foreignId('import_batch_id')->constrained('import_batches', 'id', 'ir_batch_fk')->cascadeOnDelete();
                $t->unsignedInteger('row_no');
                $t->text('raw');                         // JSON list of cell values
                $t->string('status', 20)->default('pending'); // pending|valid|error|unmatched|committed|failed
                $t->string('action', 20)->nullable();         // create|update|unchanged|schedule
                $t->text('errors')->nullable();               // JSON list
                $t->text('diff')->nullable();                 // JSON field => [old, new]
                $t->string('key_label', 190)->nullable();
            });
        }
        $this->ensureIndex('import_rows', ['import_batch_id', 'row_no'], 'ir_batch_row_uq', true);
        $this->ensureIndex('import_rows', ['import_batch_id', 'status'], 'ir_batch_status_idx');
        if (! Schema::hasTable('vendor_items')) {
            Schema::create('vendor_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('vendor_id')->constrained('vendors', 'id', 'vi_vendor_fk')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products', 'id', 'vi_product_fk')->cascadeOnDelete();
                $t->string('vendor_item_no', 60);
                $t->decimal('last_cost', 12, 4)->nullable();
                $t->date('last_cost_on')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('vendor_items', ['vendor_id', 'vendor_item_no'], 'vi_vendor_item_uq', true);
        $this->ensureIndex('vendor_items', ['product_id'], 'vi_product_idx');
        if (! Schema::hasTable('scheduled_costs')) {
            Schema::create('scheduled_costs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained('products', 'id', 'sc_product_fk')->cascadeOnDelete();
                $t->foreignId('vendor_id')->nullable()->constrained('vendors', 'id', 'sc_vendor_fk')->nullOnDelete();
                $t->foreignId('import_batch_id')->nullable()->constrained('import_batches', 'id', 'sc_batch_fk')->nullOnDelete();
                $t->decimal('old_cost', 12, 4)->nullable();
                $t->decimal('cost', 12, 4);
                $t->date('effective_on');
                $t->timestamp('applied_at')->nullable();
                $t->timestamps();
            });
        }
        $this->ensureIndex('scheduled_costs', ['applied_at', 'effective_on'], 'sc_due_idx');
    }

    public function down(): void
    {
        foreach (['scheduled_costs', 'vendor_items', 'import_rows', 'import_batches', 'import_mappings'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function ensureIndex(string $table, array $cols, string $name, bool $unique = false): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($cols, $name) : $t->index($cols, $name));
        }
    }
};
