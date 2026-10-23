<?php

namespace App\Http\Middleware;

use App\Dba\InstanceState;
use App\Models\Company;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/** 0.20.8: global. While the manager has this instance suspended, every page (admin, portal, POS, driver, warehouse,
 * quick tools, Livewire) shows the suspended page. Only /dba-api (so the manager can resume) and /up stay reachable. */
class DbaSuspended
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('dba-api', 'dba-api/*', 'up') || ! InstanceState::suspended()) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => 'This account is suspended.'], 503);
        }

        return response()->view('dba.suspended', ['company' => Company::current(), 'reason' => Setting::get('dba_suspended_reason')], 503)
            ->header('Retry-After', '3600');
    }
}
