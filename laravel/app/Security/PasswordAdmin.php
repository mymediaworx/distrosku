<?php

namespace App\Security;

use App\Audit\Audit;
use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Phase 16: admin password reset (temporary password and/or emailed link) + forced change on next sign-in. No 2FA. */
class PasswordAdmin
{
    public const MIN = 12;

    public static function mailConfigured(): bool
    {
        return ! in_array(config('mail.default'), [null, '', 'log', 'array'], true) && filled(config('mail.from.address'));
    }

    public static function canReset(User $by, User $target): bool
    {
        if (! $by->active || ! $by->isAdmin()) {
            return false;
        }

        return match ($target->role) {
            Role::Owner => $by->isOwner(),
            Role::BrandworxAdmin => $by->hasRole(Role::BrandworxAdmin), // 0.17.2: owners can no longer reset a BrandWorx admin
            default => true,
        };
    }

    public static function generate(): string
    {
        return Str::password(16, symbols: false);
    }

    public function setTemporary(User $by, User $target, string $password, bool $forceChange = true): void
    {
        if ($target->hasRole(Role::BrandworxAdmin) && ! $by->hasRole(Role::BrandworxAdmin)) {
            BrandworxProtection::deny($by, $target, 'reset the password of');
        }
        if (! self::canReset($by, $target)) {
            throw new WorkflowException('You cannot reset this user\'s password.');
        }
        if (mb_strlen($password) < self::MIN) {
            throw new WorkflowException('Password must be at least '.self::MIN.' characters.');
        }
        DB::transaction(function () use ($by, $target, $password, $forceChange) {
            $target->forceFill(['password' => Hash::make($password), 'must_change_password' => $forceChange, 'password_changed_at' => now(),
                'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
                DB::table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete(); // sign them out elsewhere
            }
            Audit::action('password_reset', $target, "Temporary password set by {$by->name}".($forceChange ? '; must change at next sign-in' : ''), ['must_change_password' => $forceChange]);
        });
    }

    public function sendLink(User $by, User $target, bool $forceChange = false): string
    {
        if ($target->hasRole(Role::BrandworxAdmin) && ! $by->hasRole(Role::BrandworxAdmin)) {
            BrandworxProtection::deny($by, $target, 'reset the password of');
        }
        if (! self::canReset($by, $target)) {
            throw new WorkflowException('You cannot reset this user\'s password.');
        }
        if (! self::mailConfigured()) {
            throw new WorkflowException('Email is not configured on this install; set a temporary password instead.');
        }
        $status = Password::broker()->sendResetLink(['email' => $target->email]);
        if ($forceChange) {
            $target->forceFill(['must_change_password' => true])->save();
        }
        Audit::action('password_reset_link', $target, "Reset link sent by {$by->name} ({$status})");

        return $status;
    }

    public function changeOwn(User $u, string $current, string $new): void
    {
        if (! Hash::check($current, $u->password)) {
            throw new WorkflowException('Current password is incorrect.');
        }
        if (mb_strlen($new) < self::MIN || Hash::check($new, $u->password)) {
            throw new WorkflowException('Choose a new password of at least '.self::MIN.' characters, different from the current one.');
        }
        $u->forceFill(['password' => Hash::make($new), 'must_change_password' => false, 'password_changed_at' => now()])->save();
    }
}
