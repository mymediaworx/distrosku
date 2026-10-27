<?php

namespace App\Support;

use App\Models\Setting;

/** 0.20.17: B2B Customer Portal settings (Company Settings > B2B Customer Portal), one place. */
final class PortalSettings
{
    public static function enabled(): bool
    {
        $v = Setting::get('portal_enabled');

        return $v === null || $v === '' ? true : (bool) (int) $v; // default on: existing installs keep working
    }

    public static function disabledMessage(): string
    {
        return trim((string) Setting::get('portal_disabled_message')) ?: 'The customer portal is temporarily unavailable. Please call us to place or check an order.';
    }
}
