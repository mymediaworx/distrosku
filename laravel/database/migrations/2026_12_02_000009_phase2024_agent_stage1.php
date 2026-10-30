<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.24 (#35 Stage 1, approved by Michael 2026-10-30). Additive and re-runnable. Read-only AI agent access over MCP:
 * per-user access tokens (only a SHA-256 hash is stored) and a log of every tool call. OFF by default (Company Settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tokens')) {
            Schema::create('agent_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('name', 80);
                $t->char('token_hash', 64)->unique();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('agent_actions')) {
            Schema::create('agent_actions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('agent_token_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('tool', 60);
                $t->char('input_hash', 64);
                $t->json('input')->nullable();
                $t->boolean('ok')->default(true);
                $t->string('error', 255)->nullable();
                $t->unsignedInteger('ms')->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->index(['user_id', 'created_at'], 'agent_actions_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
        Schema::dropIfExists('agent_tokens');
    }
};
