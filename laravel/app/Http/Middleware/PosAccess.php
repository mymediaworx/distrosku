<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Pos\PosPin;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * POS access: a full (password) login with POS rights, or a PIN session (session key only; the web guard is set for this
 * request, never persisted, so a PIN never opens /admin). 10 minutes idle returns the terminal to the PIN lock screen.
 * A password login on /pos enrolls the device as a POS terminal (cookie), which is required to show the lock screen.
 */
class PosAccess
{
    public function handle(Request $request, Closure $next)
    {
        $s = $request->session();
        $pinId = $s->get(PosPin::SESSION_USER);
        $full = Auth::guard('web')->check() && ! $pinId;
        $user = $full ? Auth::guard('web')->user() : ($pinId ? User::find($pinId) : null);

        $last = (int) $s->get(PosPin::SESSION_SEEN, 0);
        if ($user && $last && time() - $last > PosPin::IDLE_SECONDS) {
            self::lock($request);
            $user = null;
        }
        if (Company::isSuspended() && ! ($full && $user?->hasRole('brandworx_admin'))) {
            if ($pinId) {
                $s->forget(PosPin::SESSION_USER);
            }
            if ($full) {
                Auth::guard('web')->logout();
            }

            return response()->view('catalog.suspended', ['company' => Company::current()], 403);
        }
        if ($full && $user && (! $user->active || ! $user->canModule('pos', 'manage'))) {
            abort(403); // signed in with a password but no POS rights
        }
        if (! $user || ! $user->active || ! $user->canModule('pos', 'manage')
            || (Company::isSuspended() && ! $user->hasRole('brandworx_admin'))) {
            if ($pinId) {
                $s->forget(PosPin::SESSION_USER);
            }
            $enrolled = $request->cookie(PosPin::TERMINAL_COOKIE) === '1';
            if ($request->hasHeader('X-Livewire')) {
                abort(401, 'POS locked');
            }

            return new RedirectResponse($enrolled ? route('pos.lock') : url('/admin/login'));
        }
        if (! $full) {
            Auth::guard('web')->setUser($user); // this request only
        }
        $s->put(PosPin::SESSION_SEEN, time());
        $response = $next($request);
        if (! $full) {
            Auth::guard('web')->forgetUser(); // never leaks past this request (long-running workers, tests)
        }
        if ($full && $request->cookie(PosPin::TERMINAL_COOKIE) !== '1') {
            $response->headers->setCookie(Cookie::make(PosPin::TERMINAL_COOKIE, '1', 60 * 24 * 365));
        }

        return $response;
    }

    /** Back to the lock screen: end the PIN session and any password login on this device; the cart stays in the session. */
    public static function lock(Request $request): void
    {
        $s = session(); // the app session store (also set during Livewire tests)
        $s->forget([PosPin::SESSION_USER, PosPin::SESSION_SEEN]);
        if (Auth::guard('web')->check()) {
            $keep = $s->get('pos_cart');
            Auth::guard('web')->logout();
            $s->regenerate();
            if ($keep) {
                $s->put('pos_cart', $keep);
            }
        }
    }
}
