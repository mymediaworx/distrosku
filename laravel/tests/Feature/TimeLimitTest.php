<?php

namespace Tests\Feature;

use App\Support\TimeLimit;
use Tests\TestCase;

/** Imports / backups must never put a time limit on an unlimited (CLI) process: it killed the CI suite part-way. */
class TimeLimitTest extends TestCase
{
    public function test_unlimited_process_stays_unlimited(): void
    {
        $before = ini_get('max_execution_time');
        set_time_limit(0);
        TimeLimit::extend(120);
        $this->assertSame('0', ini_get('max_execution_time'));
        set_time_limit((int) $before);
    }

    public function test_no_raw_set_time_limit_calls_in_app_code(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && ! str_ends_with($f->getPathname(), 'Support/TimeLimit.php')
                && str_contains(file_get_contents($f->getPathname()), 'set_time_limit(')) {
                $hits[] = $f->getPathname();
            }
        }
        $this->assertSame([], $hits, 'Use App\Support\TimeLimit::extend()');
    }
}
