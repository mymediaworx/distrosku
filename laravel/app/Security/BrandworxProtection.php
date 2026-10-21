<?php

namespace App\Security;

use App\Audit\Audit;
use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\User;

/**
 * 0.17.2: a BrandWorx admin account can only be deactivated, deleted, re-roled,
 * password-reset or have its email changed by another BrandWorx admin.
 * 0.17.3: its name is protected too, and only a BrandWorx admin can assign the brandworx_admin role.
 * Console / unauthenticated contexts (artisan, password-reset link flow) are not blocked.
 */
class BrandworxProtection
{
    public const PROTECTED = ['active', 'role', 'email', 'password', 'name'];

    public static function isBwx(User $u, bool $original = false): bool
    {
        $r = $original ? $u->getOriginal('role') : $u->role;
        $v = $r instanceof Role ? $r->value : (string) $r;

        return $v === Role::BrandworxAdmin->value;
    }

    /** May $by change protected things on $target? */
    public static function allows(?User $by, User $target): bool
    {
        return ! self::isBwx($target, $target->exists) || ($by && $by->hasRole(Role::BrandworxAdmin));
    }

    public static function deny(?User $by, User $target, string $what): never
    {
        Audit::action('bwx_protect_blocked', $target->exists ? $target : null,
            sprintf('Blocked: %s tried to %s BrandWorx admin %s', $by?->name ?? 'unknown', $what, $target->email), ['attempt' => $what]);

        throw new WorkflowException('Only a BrandWorx admin can '.$what.($what === 'assign the brandworx_admin role to' ? ' a user.' : ' a BrandWorx admin account.'));
    }

    public static function guardSave(User $target): void
    {
        $by = auth()->user();
        if (! $by instanceof User) {
            return;
        }
        // 0.17.3: granting the role (create or edit) needs a BrandWorx admin.
        if (self::isBwx($target) && (! $target->exists || $target->isDirty('role')) && ! $by->hasRole(Role::BrandworxAdmin)) {
            self::deny($by, $target, 'assign the brandworx_admin role to');
        }
        if (! $target->exists || self::allows($by, $target)) {
            return;
        }
        $dirty = array_values(array_intersect(self::PROTECTED, array_keys($target->getDirty())));
        if ($dirty) {
            $map = ['active' => 'deactivate', 'role' => 'change the role of', 'email' => 'change the email of', 'password' => 'reset the password of', 'name' => 'rename'];
            self::deny($by, $target, $map[$dirty[0]]);
        }
    }

    public static function guardDelete(User $target): void
    {
        $by = auth()->user();
        if ($by instanceof User && ! self::allows($by, $target)) {
            self::deny($by, $target, 'delete');
        }
    }
}
