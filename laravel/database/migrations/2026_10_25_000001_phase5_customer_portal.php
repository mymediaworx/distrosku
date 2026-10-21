<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ship_to_id')->nullable()->constrained('ship_tos')->nullOnDelete(); // optional restriction
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone', 30)->nullable();
            $t->string('password')->nullable(); // null until the set-password link is used
            $t->boolean('active')->default(true);
            $t->string('setup_token_hash', 64)->nullable()->unique(); // sha256 of the one-time link token
            $t->timestamp('setup_token_expires_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_contact_id'));
        Schema::dropIfExists('customer_contacts');
    }
};
