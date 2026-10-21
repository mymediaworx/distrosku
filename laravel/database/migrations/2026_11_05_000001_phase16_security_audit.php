<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 16 (0.16.0): append-only activity log + forced password change. Re-run safe; key names <= 22 chars. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $t) {
                $t->id();
                $t->string('event', 40);              // created|updated|deleted|login|logout|login_failed|password_reset|role_changed|deliver|sign|...
                $t->string('subject_type', 120)->nullable();
                $t->string('subject_id', 64)->nullable(); // string: some models (settings) key by name
                $t->string('subject_label', 190)->nullable();
                $t->unsignedBigInteger('user_id')->nullable(); // no FK: history survives user deletion
                $t->string('user_name', 120)->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->longText('old_values')->nullable();
                $t->longText('new_values')->nullable();
                $t->string('description', 255)->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
        $this->ensureIndex('activity_logs', ['subject_type', 'subject_id', 'id'], 'al_subject_idx');
        $this->ensureIndex('activity_logs', ['user_id', 'created_at'], 'al_user_created_idx');
        $this->ensureIndex('activity_logs', ['event', 'created_at'], 'al_event_created_idx');
        $this->ensureIndex('activity_logs', ['created_at'], 'al_created_idx');
        if (Schema::hasTable('users')) {
            foreach (['must_change_password' => fn (Blueprint $t) => $t->boolean('must_change_password')->default(false),
                'password_changed_at' => fn (Blueprint $t) => $t->timestamp('password_changed_at')->nullable()] as $c => $fn) {
                if (! Schema::hasColumn('users', $c)) {
                    Schema::table('users', $fn);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }

    private function ensureIndex(string $table, array $cols, string $name): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
        }
    }
};
