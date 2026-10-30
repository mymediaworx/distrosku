<?php

namespace App\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #22: what an uptime monitor (UptimeRobot, Michael's choice) needs to know. /health answers 200 "OK" when the database
 * answers, the scheduler (cron -> schedule:run) ran in the last 5 minutes and the database queue isn't stuck; otherwise
 * 503 with the failing check names. No customer, money or config details are ever in the response.
 */
class HealthCheck
{
    public const HEARTBEAT_KEY = 'health:scheduler_heartbeat';

    public const SCHEDULER_MAX_AGE = 300;

    public const QUEUE_MAX_AGE = 900;

    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->timestamp);
    }

    /** @return array<string, bool> */
    public static function checks(): array
    {
        $out = [];
        try {
            DB::select('select 1');
            $out['database'] = true;
        } catch (\Throwable) {
            $out['database'] = false;
        }
        try {
            $beat = (int) Cache::get(self::HEARTBEAT_KEY, 0);
            $out['scheduler'] = $beat > 0 && now()->timestamp - $beat <= self::SCHEDULER_MAX_AGE;
        } catch (\Throwable) {
            $out['scheduler'] = false;
        }
        try {
            $oldest = $out['database'] && Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
            $out['queue'] = $oldest === null || now()->timestamp - (int) $oldest <= self::QUEUE_MAX_AGE;
        } catch (\Throwable) {
            $out['queue'] = false;
        }

        return $out;
    }
}
