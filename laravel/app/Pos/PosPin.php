<?php

namespace App\Pos;

use App\Exceptions\WorkflowException;
use App\Models\PosPinLogin;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 4-digit POS PINs. Stored as bcrypt (pos_pin_hash) plus an HMAC-SHA256 keyed with APP_KEY (pos_pin_lookup, unique index)
 * so PINs are unique per install and can be checked without keeping the PIN. A PIN only opens the POS (session key, not the web guard).
 * Wrong PINs: 5 per employee then that employee is locked for 5 minutes; 30 per terminal IP per 5 minutes.
 */
class PosPin
{
    public const MAX_TRIES = 5;

    public const LOCK_SECONDS = 300;

    public const IDLE_SECONDS = 600;

    public const SESSION_USER = 'pos_pin_user_id';

    public const SESSION_SEEN = 'pos_last_activity';

    public const TERMINAL_COOKIE = 'pos_terminal';

    public static function lookup(string $pin): string
    {
        return hash_hmac('sha256', 'pos-pin:'.$pin, (string) config('app.key'));
    }

    public static function valid(string $pin): bool
    {
        return (bool) preg_match('/^\d{4}$/', $pin);
    }

    public function set(User $user, string $pin, ?User $by = null): void
    {
        if (! self::valid($pin)) {
            throw new WorkflowException('The PIN must be exactly 4 digits.');
        }
        if (in_array($pin, ['0000', '1111', '1234', '4321', '9999'], true)) {
            throw new WorkflowException('Choose a less obvious PIN.');
        }
        $lookup = self::lookup($pin);
        if (User::where('pos_pin_lookup', $lookup)->whereKeyNot($user->id)->exists()) {
            throw new WorkflowException('That PIN is already used by another employee. Choose another.');
        }
        $user->forceFill(['pos_pin_hash' => Hash::make($pin), 'pos_pin_lookup' => $lookup, 'pos_pin_set_at' => now()])->save();
        $this->log($user, 'pin_set');
    }

    public function clear(User $user): void
    {
        $user->forceFill(['pos_pin_hash' => null, 'pos_pin_lookup' => null, 'pos_pin_set_at' => null])->save();
    }

    public static function key(User $u): string
    {
        return 'pos-pin:'.$u->id;
    }

    public function lockedFor(User $u): int
    {
        return RateLimiter::tooManyAttempts(self::key($u), self::MAX_TRIES) ? RateLimiter::availableIn(self::key($u)) : 0;
    }

    /** Check the chosen employee's PIN. Returns the user on success; throws with a reason otherwise. */
    public function attempt(User $u, string $pin, ?string $ip = null): User
    {
        $ipKey = 'pos-pin-ip:'.($ip ?? 'none');
        if (RateLimiter::tooManyAttempts($ipKey, 30)) {
            throw new WorkflowException('Too many wrong PINs from this terminal. Try again in '.ceil(RateLimiter::availableIn($ipKey) / 60).' min.');
        }
        if (\App\Models\Company::isSuspended() && ! $u->hasRole('brandworx_admin')) {
            $this->log($u, 'suspended');
            throw new WorkflowException('This account is suspended. The POS is unavailable.');
        }
        if ($s = $this->lockedFor($u)) {
            $this->log($u, 'locked');
            throw new WorkflowException("{$u->name} is locked for ".ceil($s / 60).' min after '.self::MAX_TRIES.' wrong PINs.');
        }
        if (! $u->active || ! $u->canModule('pos', 'manage') || ! $u->pos_pin_hash || ! self::valid($pin) || ! Hash::check($pin, $u->pos_pin_hash)) {
            RateLimiter::hit(self::key($u), self::LOCK_SECONDS);
            RateLimiter::hit($ipKey, self::LOCK_SECONDS);
            $this->log($u, 'failed');
            $left = self::MAX_TRIES - RateLimiter::attempts(self::key($u));
            throw new WorkflowException($left > 0 ? "Wrong PIN. {$left} tries left." : "Wrong PIN. {$u->name} is locked for ".(self::LOCK_SECONDS / 60).' min.');
        }
        RateLimiter::clear(self::key($u));
        $this->log($u, 'success');

        return $u;
    }

    public function log(?User $u, string $event): void
    {
        PosPinLogin::create(['user_id' => $u?->id, 'event' => $event, 'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255)]);
    }
}
