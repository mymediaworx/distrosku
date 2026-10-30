<?php

namespace App\Agent;

use App\Audit\Audit;
use App\Models\AgentToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/** #35 Stage 1: turning agent access on/off and issuing / checking / revoking tokens. */
class AgentAccess
{
    public static function enabled(): bool
    {
        return (string) Setting::get('agent_enabled', '0') === '1';
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** @return array{token: AgentToken, plain: string} the plain token is shown once */
    public function issue(User $for, string $name, User $by, ?int $days = 90): array
    {
        $plain = 'dsk_'.Str::random(48);
        $t = AgentToken::create(['user_id' => $for->id, 'name' => mb_substr(trim($name) ?: 'AI agent', 0, 80), 'token_hash' => self::hash($plain),
            'expires_at' => $days ? now()->addDays($days) : null, 'created_by' => $by->id]);
        Audit::action('agent_token_created', $t, "AI agent token \"{$t->name}\" for {$for->name}", ['expires_at' => $t->expires_at?->toDateString()]);

        return ['token' => $t, 'plain' => $plain];
    }

    public function revoke(AgentToken $t, User $by): void
    {
        $t->update(['revoked_at' => now()]);
        Audit::action('agent_token_revoked', $t, "AI agent token \"{$t->name}\" revoked by {$by->name}");
    }

    public static function find(?string $plain): ?AgentToken
    {
        if (! $plain || ! str_starts_with($plain, 'dsk_')) {
            return null;
        }
        $t = AgentToken::with('user')->where('token_hash', self::hash($plain))->first();

        return $t && $t->isUsable() ? $t : null;
    }
}
