<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Portal guard: signed-in active contact of an active customer; account suspension blocks the whole portal. */
class PortalAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        if (Company::isSuspended()) {
            if (Auth::guard('portal')->check()) {
                Auth::guard('portal')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->view('portal.suspended', [], 403);
        }
        $c = Auth::guard('portal')->user();
        if (! $c) {
            return redirect()->guest(route('portal.login'));
        }
        if (! $c->canUsePortal()) {
            Auth::guard('portal')->logout();
            $request->session()->invalidate();

            return redirect()->route('portal.login')->with('portal_error', 'Your portal access is disabled. Please contact us.');
        }

        return $next($request);
    }
}
