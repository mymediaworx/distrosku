<?php

namespace App\Warehouse;

use App\Models\Product;
use App\Models\Setting;

/**
 * 0.20.15 (#36): lot tracking switched on by product type. Departments and tax classes listed here make NEW products
 * lot-tracked by default (an explicit track_lots on the product always wins). applyToExisting() turns it on for
 * products already in those types. Off by default: nothing is tracked until an owner picks types in Settings.
 */
final class LotTracking
{
    public static function departments(): array
    {
        return array_map('intval', json_decode((string) Setting::get('lot_track_departments', '[]'), true) ?: []);
    }

    public static function taxClasses(): array
    {
        return json_decode((string) Setting::get('lot_track_tax_classes', '[]'), true) ?: [];
    }

    public static function save(array $departmentIds, array $taxClasses): void
    {
        Setting::put('lot_track_departments', json_encode(array_values(array_map('intval', $departmentIds))));
        Setting::put('lot_track_tax_classes', json_encode(array_values(array_map('strval', $taxClasses))));
    }

    public static function appliesTo(Product $p): bool
    {
        $cls = $p->tax_class instanceof \BackedEnum ? $p->tax_class->value : $p->tax_class;

        return ($p->department_id && in_array((int) $p->department_id, self::departments(), true))
            || ($cls && in_array($cls, self::taxClasses(), true));
    }

    /** Turn tracking on for existing products in the chosen types. Never turns it off. Returns how many changed. */
    public static function applyToExisting(): int
    {
        $deps = self::departments();
        $cls = self::taxClasses();
        if (! $deps && ! $cls) {
            return 0;
        }

        return Product::where('track_lots', false)->where(fn ($q) => $q->whereIn('department_id', $deps ?: [0])->orWhereIn('tax_class', $cls ?: ['__none__']))
            ->update(['track_lots' => true]);
    }
}
