<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Exact-decimal helpers. Never use floats for money or quantities. */
final class Money
{
    public static function of(mixed $v): BigDecimal
    {
        if ($v instanceof BigDecimal) {
            return $v;
        }

        return BigDecimal::of($v === null || $v === '' ? '0' : (string) $v);
    }

    public static function mul(mixed $a, mixed $b, int $scale = 4): string
    {
        return (string) self::of($a)->multipliedBy(self::of($b))->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function add(mixed ...$vals): string
    {
        $sum = BigDecimal::zero();
        foreach ($vals as $v) {
            $sum = $sum->plus(self::of($v));
        }

        return (string) $sum->toScale(4, RoundingMode::HALF_UP);
    }

    public static function s(mixed $v): string
    {
        return (string) self::of($v)->toScale(4, RoundingMode::HALF_UP);
    }
}
