<?php

namespace App\Inventory;

use App\Models\Setting;

/**
 * 0.20.20 (walkthrough #9): company setting "Allow negative inventory sales".
 * OFF by default, which is how Vega has always behaved (a sale can never take stock below zero).
 * ON lets a SALE (reserve at confirm, stock-out at delivery) run on-hand below zero; counts, transfers,
 * receipts and adjustments are never allowed to go negative.
 */
class NegativeStock
{
    public const KEY = 'allow_negative_inventory_sales';

    public static function allowed(): bool
    {
        return (string) (Setting::get(self::KEY) ?? '0') === '1';
    }

    public static function set(bool $on): void
    {
        Setting::put(self::KEY, $on ? '1' : '0');
    }
}
