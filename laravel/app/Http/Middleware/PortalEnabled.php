<?php

namespace App\Http\Middleware;

use App\Support\PortalSettings;
use Closure;
use Illuminate\Http\Request;

/** 0.20.17: Company Settings > B2B Customer Portal "Portal enabled" switch. Off = a plain notice (503), nothing else changes. */
class PortalEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! PortalSettings::enabled()) {
            return response()->view('portal.disabled', ['message' => PortalSettings::disabledMessage()], 503);
        }

        return $next($request);
    }
}
