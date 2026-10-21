<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Phase 16: a user flagged must_change_password can only reach the change-password page (and sign out). */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if ($u?->must_change_password && ! $request->routeIs('filament.admin.pages.change-password', 'filament.admin.auth.logout')
            && ! str_starts_with($request->path(), 'livewire/')) {
            return redirect()->route('filament.admin.pages.change-password');
        }

        return $next($request);
    }
}
