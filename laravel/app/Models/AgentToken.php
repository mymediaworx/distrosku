<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #35: an access token for the read-only MCP endpoint. Acts as its user (same permissions). Only the hash is stored. */
class AgentToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture()) && $this->user?->active;
    }
}
