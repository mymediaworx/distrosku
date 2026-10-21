<?php

namespace App\Marketing;

use App\Models\Setting;

/**
 * 0.19: BrandWorx Marketing add-on (white-label HighLevel). Settings are stored encrypted (Setting casts value => encrypted).
 * The token is never returned to the UI in full. API host is fixed; the user-facing app URL is a setting.
 */
class MarketingSettings
{
    public const API = 'https://services.leadconnectorhq.com';

    public const API_VERSION = '2021-07-28';

    public const DEFAULT_APP_URL = 'https://app.brandworx.ai';

    public static function token(): ?string
    {
        return Setting::get('mkt_token');
    }

    public static function locationId(): ?string
    {
        return Setting::get('mkt_location_id');
    }

    public static function appUrl(): string
    {
        return rtrim((string) Setting::get('mkt_app_url', self::DEFAULT_APP_URL), '/');
    }

    public static function webhookUrl(): ?string
    {
        return Setting::get('mkt_webhook_url');
    }

    public static function configured(): bool
    {
        return filled(self::token()) && filled(self::locationId());
    }

    /** Module visible / active only when configured AND switched on. */
    public static function enabled(): bool
    {
        return self::configured() && Setting::get('mkt_enabled') === '1';
    }

    /** Transactional email/SMS: separate switch, off by default. */
    public static function notifyEnabled(): bool
    {
        return self::enabled() && Setting::get('mkt_notify_enabled') === '1';
    }

    public static function maskedToken(): string
    {
        $t = (string) self::token();

        return $t === '' ? 'Not set' : 'Set (ends …'.substr($t, -4).')';
    }

    public static function contactUrl(?string $contactId): ?string
    {
        return $contactId && self::locationId() ? self::appUrl().'/v2/location/'.rawurlencode(self::locationId()).'/contacts/detail/'.rawurlencode($contactId) : null;
    }

    public static function conversationsUrl(): ?string
    {
        return self::locationId() ? self::appUrl().'/v2/location/'.rawurlencode(self::locationId()).'/conversations/conversations' : null;
    }
}
