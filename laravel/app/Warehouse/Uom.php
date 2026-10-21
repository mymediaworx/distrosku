<?php

namespace App\Warehouse;

use App\Models\Product;
use App\Services\Money;
use Brick\Math\RoundingMode;

/** Units of measure: every product stocks in base units; units (case, carton, pack...) are multiples of the base. */
class Uom
{
    /** Largest unit first. */
    public static function units(Product $p)
    {
        return $p->units()->orderByDesc('factor')->get();
    }

    public static function toBase(Product $p, mixed $qty, ?string $unitCode = null): string
    {
        if (! $unitCode) {
            return Money::s($qty);
        }
        $u = $p->units()->where('code', $unitCode)->first();
        if (! $u) {
            throw new \App\Exceptions\WorkflowException("{$p->sku} has no unit '$unitCode'.");
        }

        return Money::mul($qty, $u->factor);
    }

    /** "2 CS + 3 CTN + 4 Pack" for a base quantity (whole units only, remainder in base). */
    public static function describe(Product $p, mixed $qty): string
    {
        $left = Money::of($qty);
        if ($left->isZero()) {
            return '0';
        }
        $parts = [];
        foreach (self::units($p) as $u) {
            $f = Money::of($u->factor);
            if ($f->isLessThanOrEqualTo(Money::of(1))) {
                continue;
            }
            $n = $left->dividedBy($f, 0, RoundingMode::DOWN);
            if ($n->isPositive()) {
                $parts[] = $n.' '.$u->code;
                $left = $left->minus($n->multipliedBy($f));
            }
        }
        if (! $left->isZero() || ! $parts) {
            $parts[] = rtrim(rtrim((string) $left->toScale(4), '0'), '.').' '.$p->base_unit_name;
        }

        return implode(' + ', $parts);
    }
}
