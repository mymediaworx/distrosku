<?php

namespace App\Pos;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Warehouse\ProductLookup;

/**
 * Issue #25: what a scan or typed term means at a sales screen (counter POS, van sales).
 * Any barcode level works: a case or carton barcode adds that unit's factor in base units
 * (stock and sales lines stay in base units, as in the warehouse). Falls back to a name search.
 */
final class SaleScan
{
    /**
     * @return array{product: Product, qty: string, unit: ?ProductUnit}|null
     */
    public static function resolve(string $term): ?array
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        $hit = ProductLookup::resolve($term); // extra barcodes (case/carton/pack), UPC 12/13-digit forms, MSA UPC, SKU
        if ($hit && self::sellable($hit['product'])) {
            return ['product' => $hit['product'], 'qty' => self::whole($hit['factor']), 'unit' => $hit['unit']];
        }

        $byName = \App\Support\ProductSearch::query($term)->first(); // 0.20.19: every word matches, any order, best match first

        return $byName ? ['product' => $byName, 'qty' => '1', 'unit' => null] : null;
    }

    /** "Added 1 CTN (10 Pack) × TEST Cigarette Kings." or "Added 1 × TEST Lighter." */
    public static function addedMessage(array $hit): string
    {
        $p = $hit['product'];
        $unit = $hit['unit'];
        if ($unit && $hit['qty'] !== '1') {
            return "Added 1 {$unit->code} ({$hit['qty']} {$p->base_unit_name}) × {$p->name}.";
        }

        return "Added 1 × {$p->name}.";
    }

    private static function sellable(Product $p): bool
    {
        return $p->active && ! $p->isArchived();
    }

    /** 10.0000 -> 10, 2.5000 -> 2.5 */
    private static function whole(string $factor): string
    {
        return str_contains($factor, '.') ? rtrim(rtrim($factor, '0'), '.') : $factor;
    }
}
