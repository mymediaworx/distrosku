<?php

namespace App\Filament\Concerns;

/** Platform (master) area: brandworx_admin only. */
trait PlatformOnly
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('brandworx_admin');
    }
}
