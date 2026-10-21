<?php

namespace App\Imports;

use InvalidArgumentException;

class ImportRegistry
{
    public const TYPES = [
        'customers' => Types\CustomersImport::class,
        'ship_tos' => Types\ShipTosImport::class,
        'products' => Types\ProductsImport::class,
        'vendors' => Types\VendorsImport::class,
        'opening_inventory' => Types\OpeningInventoryImport::class,
        'price_lists' => Types\PriceListsImport::class,
        'opening_balances' => Types\OpeningBalancesImport::class,
        'vendor_costs' => Types\VendorCostsImport::class,
    ];

    public static function make(string $type): Types\ImportType
    {
        $c = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown import type {$type}");

        return new $c;
    }

    /** @return array<string,string> */
    public static function options(bool $vendorFiles = false): array
    {
        $o = [];
        foreach (self::TYPES as $k => $c) {
            $t = new $c;
            if ($t->needsVendor() === $vendorFiles) {
                $o[$k] = $t->label();
            }
        }

        return $o;
    }
}
