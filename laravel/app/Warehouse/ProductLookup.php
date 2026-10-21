<?php

namespace App\Warehouse;

use App\Models\Product;
use App\Models\ProductBarcode;

/**
 * Resolve a scanned code: extra barcodes (per unit: case / carton / pack), then UPC (12/13-digit forms), MSA selling-unit UPC, SKU.
 * resolve() returns the product plus how many base (stock) units one scan of that code means.
 */
class ProductLookup
{
    /** @return string[] the code and its UPC-A / EAN-13 variants */
    public static function variants(string $code): array
    {
        $code = trim($code);
        $digits = preg_replace('/\D/', '', $code);
        $v = [$code];
        if ($digits !== '' && $digits !== $code) {
            $v[] = $digits;
        }
        if (strlen($digits) === 12) {
            $v[] = '0'.$digits;
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $v[] = substr($digits, 1);
        }

        return array_values(array_unique(array_filter($v, fn ($x) => $x !== '')));
    }

    /** @return array{product: Product, factor: string, unit: ?\App\Models\ProductUnit}|null */
    public static function resolve(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $bc = ProductBarcode::with('product', 'unit')->whereIn('code', self::variants($code))->first();
        if ($bc && $bc->product) {
            return ['product' => $bc->product, 'factor' => $bc->unit ? (string) $bc->unit->factor : '1', 'unit' => $bc->unit];
        }
        $p = self::findBase($code);

        return $p ? ['product' => $p, 'factor' => '1', 'unit' => null] : null;
    }

    public static function find(string $code): ?Product
    {
        return self::resolve($code)['product'] ?? null;
    }

    private static function findBase(string $code): ?Product
    {
        $vars = self::variants($code);

        return Product::query()->where(fn ($w) => $w->whereIn('upc', $vars)->orWhereIn('msa_upc', $vars)->orWhereRaw('LOWER(sku) = ?', [mb_strtolower($code)]))
            ->orderByRaw('archived_at IS NULL DESC')->first();
    }

    /** Is this code already used by another product (barcodes, UPC)? */
    public static function takenBy(string $code, ?int $exceptProductId = null): ?Product
    {
        $vars = self::variants($code);
        $bc = ProductBarcode::whereIn('code', $vars)->when($exceptProductId, fn ($q) => $q->where('product_id', '!=', $exceptProductId))->first();
        if ($bc) {
            return $bc->product;
        }

        return Product::whereIn('upc', $vars)->when($exceptProductId, fn ($q) => $q->whereKeyNot($exceptProductId))->first();
    }
}
