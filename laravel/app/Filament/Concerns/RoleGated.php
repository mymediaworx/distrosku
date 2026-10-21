<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/** Resource access by role. Set `protected static string $module` on the resource. */
trait RoleGated
{
    protected static function allowed(string $ability): bool
    {
        $u = auth()->user();

        return $u !== null && $u->canModule(static::$module, $ability);
    }

    public static function canViewAny(): bool
    {
        return static::allowed('view');
    }

    public static function canView(Model $record): bool
    {
        return static::allowed('view');
    }

    public static function canCreate(): bool
    {
        return static::allowed('manage');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed('manage');
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowed('manage');
    }

    public static function canDeleteAny(): bool
    {
        return static::allowed('manage');
    }
}
