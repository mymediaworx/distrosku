<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** When the company account is suspended only brandworx_admin may stay signed in. */
class BlockWhenSuspended
{
    public function handle(Request $request, Closure $next)
    {
        if (Company::isSuspended() && ($u = Auth::user()) && ! $u->hasRole('brandworx_admin')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->view('catalog.suspended', ['company' => Company::current()], 403);
        }

        return $next($request);
    }
}
