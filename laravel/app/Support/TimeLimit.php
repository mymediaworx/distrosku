<?php

namespace App\Support;

/**
 * Give long work (imports, backups) more time without ever putting a limit on a process that has none.
 * CLI processes (artisan, queue workers, the test suite) run unlimited; calling set_time_limit(120) there would
 * start a 120-second clock for the rest of the process. That killed the CI test run part-way (exit 255).
 */
class TimeLimit
{
    public static function extend(int $seconds): void
    {
        if ((int) ini_get('max_execution_time') === 0) {
            return; // already unlimited
        }
        @set_time_limit($seconds);
    }
}
