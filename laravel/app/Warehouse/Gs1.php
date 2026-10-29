<?php

namespace App\Warehouse;

use Carbon\CarbonImmutable;

/**
 * 0.20.15 (#36): GS1-128 / DataMatrix element strings at receiving. Reads AI 01 (GTIN), 10 (lot), 17 (expiry YYMMDD)
 * in both human-readable "(01)...(17)...(10)..." and raw scanner form (optional ]C1/]d2/]Q3 prefix, FNC1 as ASCII 29).
 * Returns null when the scan is not a GS1 element string (a plain UPC stays a plain UPC).
 */
final class Gs1
{
    private const FIXED = ['00' => 18, '01' => 14, '02' => 14, '11' => 6, '12' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6, '20' => 2];

    /** @return array{gtin: ?string, lot: ?string, expires: ?string}|null */
    public static function parse(string $code): ?array
    {
        $s = trim($code);
        $ai = [];
        if (str_starts_with($s, '(')) {
            if (! preg_match_all('/\((\d{2,4})\)([^(]*)/', $s, $m, PREG_SET_ORDER)) {
                return null;
            }
            foreach ($m as $x) {
                $ai[$x[1]] = trim($x[2]);
            }
        } else {
            $s = preg_replace('/^\](C1|d2|Q3|e0)/', '', $s);
            $s = str_replace(['<GS>', '{GS}', '|'], "\x1d", $s);
            if (! preg_match('/^(01\d{14}|00\d{18}|10|17\d{6})/', $s) || strlen($s) < 16) {
                return null;
            }
            $i = 0;
            $n = strlen($s);
            while ($i < $n) {
                if ($s[$i] === "\x1d") {
                    $i++;

                    continue;
                }
                $k = substr($s, $i, 2);
                if (! ctype_digit($k)) {
                    return null;
                }
                if (isset(self::FIXED[$k])) {
                    $ai[$k] = substr($s, $i + 2, self::FIXED[$k]);
                    $i += 2 + self::FIXED[$k];

                    continue;
                }
                $end = strpos($s, "\x1d", $i + 2);
                $ai[$k] = substr($s, $i + 2, $end === false ? null : $end - $i - 2);
                $i = $end === false ? $n : $end;
            }
        }
        if (! isset($ai['01']) && ! isset($ai['10']) && ! isset($ai['17'])) {
            return null;
        }

        return ['gtin' => $ai['01'] ?? null, 'lot' => isset($ai['10']) && $ai['10'] !== '' ? mb_substr($ai['10'], 0, 20) : null, 'expires' => self::date($ai['17'] ?? null)];
    }

    /** YYMMDD; DD=00 means end of month (GS1 general spec). Century: 51-99 => 19xx is not used for expiry, always 20xx. */
    private static function date(?string $v): ?string
    {
        if (! $v || ! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }
        $d = CarbonImmutable::create(2000 + (int) $m[1], (int) $m[2], 1);

        return ((int) $m[3] === 0 ? $d->endOfMonth() : $d->setDay(min((int) $m[3], $d->daysInMonth)))->toDateString();
    }
}
