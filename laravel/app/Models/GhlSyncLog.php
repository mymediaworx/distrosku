<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.19: one BrandWorx Marketing sync / event / notification attempt. Never stores tokens or message bodies. */
class GhlSyncLog extends Model
{
    protected $guarded = ['id'];

    public static function start(string $kind, ?string $event, ?Model $subject, ?string $dedupe = null): ?self
    {
        if ($dedupe && static::where('dedupe_key', $dedupe)->whereIn('status', ['ok', 'queued'])->exists()) {
            return null; // already done / in flight
        }
        if ($dedupe) {
            static::where('dedupe_key', $dedupe)->update(['dedupe_key' => null]); // retry of a failed one: keep history
        }

        return static::create(['kind' => $kind, 'event' => $event, 'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(), 'status' => 'queued', 'dedupe_key' => $dedupe]);
    }

    public function finish(string $status, ?int $http = null, ?string $summary = null, ?string $error = null): void
    {
        $this->update(['status' => $status, 'http_status' => $http, 'attempts' => $this->attempts + 1,
            'summary' => $summary ? mb_substr($summary, 0, 500) : $this->summary, 'error' => $error ? mb_substr($error, 0, 500) : null]);
    }
}
