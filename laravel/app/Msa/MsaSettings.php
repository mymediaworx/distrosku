<?php

namespace App\Msa;

use App\Models\Setting;

/** MSA MULTICAT settings (settings table, encrypted at rest). Admin-level, edited on the MSA weekly page. */
final class MsaSettings
{
    /** key => [label, default] */
    public const KEYS = [
        'msa_distributor_id' => ['MSA distributor ID (8 characters, assigned by MSA)', null],
        'msa_name' => ['Distributor name (shipping location)', null],
        'msa_address' => ['Address', null],
        'msa_city' => ['City', null],
        'msa_state' => ['State', 'KY'],
        'msa_zip' => ['ZIP / ZIP+4', null],
        'msa_contact_first' => ['Contact first name', null],
        'msa_contact_last' => ['Contact last name', null],
        'msa_phone' => ['Contact phone (10 digits)', null],
        'msa_fax' => ['Contact fax (optional)', null],
        'msa_email' => ['Contact email (optional)', null],
        'msa_week_end_day' => ['Week ends on', '6'],           // Carbon dayOfWeek: 0 = Sunday ... 6 = Saturday
        'msa_test_mode' => ['Test file (T)', '1'],             // MSA: send Test until Distributor Support approves Live
        'msa_report_dollars' => ['Report dollars sold (measure 002)', '0'],
        'msa_altria_fields' => ['Altria STARS fields (transaction date per PUR, returns 004/005, floor returns 006)', '0'],
        'msa_tax_jurisdiction' => ['State tax jurisdiction', 'none'], // none | sid (ship-to state in SID cols 197-198)
        'msa_line_ending' => ['Line ending', 'crlf'],          // crlf | lf
        'msa_profile' => ['File profile', 'sample'],           // sample = match Vega's accepted weekly file | spec = MSA 2019 spec literal
    ];

    public const PROFILES = ['sample' => 'Match accepted sample file (2-decimal numbers, zero-padded customer #, SID followed by its PURs)', 'spec' => 'MSA 2019 spec (floating decimals, SIDs then PURs)'];

    public const DAYS = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    public static function get(string $key): ?string
    {
        $v = Setting::get($key, self::KEYS[$key][1] ?? null);

        return $v === null ? null : (string) $v;
    }

    public static function all(): array
    {
        $o = [];
        foreach (array_keys(self::KEYS) as $k) {
            $o[$k] = self::get($k);
        }

        return $o;
    }

    public static function put(array $values): void
    {
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::KEYS)) {
                Setting::put($k, $v === null ? null : trim(is_bool($v) ? ($v ? '1' : '0') : (string) $v));
            }
        }
    }

    public static function bool(string $key): bool
    {
        return in_array(self::get($key), ['1', 'true', 'yes', 'on'], true);
    }

    public static function weekEndDay(): int
    {
        $d = (int) self::get('msa_week_end_day');

        return $d >= 0 && $d <= 6 ? $d : 6;
    }
}
