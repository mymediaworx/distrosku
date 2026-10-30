<?php

namespace App\Http\Controllers;

use App\Health\HealthCheck;

/** #22: GET /health for UptimeRobot (keyword "OK" or HTTP status). */
class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = HealthCheck::checks();
        $failed = array_keys(array_filter($checks, fn ($ok) => ! $ok));

        return response($failed ? 'FAIL '.implode(',', $failed) : 'OK', $failed ? 503 : 200)
            ->header('Content-Type', 'text/plain')->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
