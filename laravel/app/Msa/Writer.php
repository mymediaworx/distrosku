<?php

namespace App\Msa;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/** Pure fixed-width formatter for MULTICAT records. No database access. */
final class Writer
{
    /** Build one record of exactly $length characters from a layout and values (missing / null = blank). */
    public static function record(array $layout, array $values, int $length): string
    {
        $line = str_repeat(' ', $length);
        foreach ($layout as [$name, $start, $len, $just]) {
            if ($start + $len - 1 > $length) {
                continue; // optional tail field beyond this record's length (e.g. Altria measures)
            }
            $v = $values[$name] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $line = substr_replace($line, self::field($v, $len, $just, $name), $start - 1, $len);
        }

        return $line;
    }

    public static function field(mixed $v, int $len, string $just, string $name = ''): string
    {
        if ($just === 'N' || $just === 'NB') {
            $s = $just === 'NB' ? self::money($v) : self::number($v);
            $neg = str_starts_with($s, '-');
            $abs = $neg ? substr($s, 1) : $s;
            if ($just === 'N') {
                $out = ($neg ? '-' : '').str_pad($abs, $len - ($neg ? 1 : 0), '0', STR_PAD_LEFT);
            } else {
                $out = str_pad($s, $len, ' ', STR_PAD_LEFT);
            }
            if (strlen($out) > $len) {
                throw new \LengthException("MULTICAT field $name: value $s does not fit in $len characters.");
            }

            return $out;
        }
        $s = self::ascii((string) $v);
        if (strlen($s) > $len) {
            if ($just === 'LB') {
                $s = substr($s, 0, $len); // descriptive text: truncate
            } else {
                throw new \LengthException("MULTICAT field $name: '$s' is longer than $len characters.");
            }
        }

        return match ($just) {
            'RB' => str_pad($s, $len, ' ', STR_PAD_LEFT),
            'RZ' => str_pad($s, $len, '0', STR_PAD_LEFT),
            default => str_pad($s, $len, ' ', STR_PAD_RIGHT),
        };
    }

    /** Real number with floating decimal: integers without a point, fractions with up to 4 decimals (e.g. 10, 1.5, -0.2). */
    public static function number(mixed $v): string
    {
        $d = BigDecimal::of((string) $v)->toScale(4, RoundingMode::HALF_UP);
        $d = method_exists($d, 'strippedOfTrailingZeros') ? $d->strippedOfTrailingZeros() : $d->stripTrailingZeros();
        $s = (string) $d;
        if (str_contains($s, 'E') || str_contains($s, 'e')) {
            $s = (string) $d->toScale(4);
        }

        return $s === '-0' ? '0' : $s;
    }

    /** Dollars: real number with 2 decimals (e.g. 300.00, -60.00). */
    public static function money(mixed $v): string
    {
        $s = (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HALF_UP);

        return $s === '-0.00' ? '0.00' : $s;
    }

    /** 7-bit printable ASCII only (fixed-width file). */
    public static function ascii(string $s): string
    {
        $s = Str::ascii($s);

        return trim(preg_replace('/[^\x20-\x7E]/', '', preg_replace('/\s+/', ' ', $s)));
    }

    public static function digits(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }
}
