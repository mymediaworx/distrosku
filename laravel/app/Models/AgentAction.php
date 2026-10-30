<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #35: one AI agent tool call (who, which tool, input, ok/error, time). */
class AgentAction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input' => 'array', 'ok' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
