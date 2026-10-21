<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4: SKU catalog (departments on existing products), shared product research, single company record. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 10)->unique(); // e.g. ELEC -> SKU ELEC-0042
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('shared_products', function (Blueprint $t) {
            $t->id();
            $t->string('normalized_name')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('image_path')->nullable();
            $t->timestamps();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $t->text('description')->nullable()->after('name');
            $t->string('image_path')->nullable()->after('description');
            $t->foreignId('shared_product_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });
        Schema::create('price_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shared_product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('price', 12, 4);
            $t->string('source', 20)->default('admin'); // admin, quick_tools
            $t->timestamps();
            $t->index(['shared_product_id', 'created_at']);
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('logo_path')->nullable();
            $t->string('public_token', 64)->nullable()->unique();
            $t->timestamp('public_token_rotated_at')->nullable();
            $t->boolean('suspended')->default(false);
            $t->string('suspended_reason')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
        Schema::dropIfExists('price_submissions');
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('shared_product_id');
            $t->dropConstrainedForeignId('department_id');
            $t->dropColumn(['description', 'image_path']);
        });
        Schema::dropIfExists('shared_products');
        Schema::dropIfExists('departments');
    }
};
