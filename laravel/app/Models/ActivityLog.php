<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only change history. Never updated or deleted by the app. */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity log entries are read-only.'));
        static::deleting(fn () => throw new LogicException('Activity log entries cannot be deleted.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function subjectShort(): string
    {
        return $this->subject_type ? class_basename($this->subject_type) : '';
    }
}
