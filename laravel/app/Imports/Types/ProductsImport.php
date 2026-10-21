<?php

namespace App\Imports\Types;

use App\Enums\TaxClass;
use App\Imports\RowResult;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Vendor;
use App\Models\VendorItem;

/** Products incl. UPC, extra barcodes and up to 3 units of measure (e.g. CT=10, CS=50) with their own barcodes. */
class ProductsImport extends ImportType
{
    public const UOMS = 3;

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products (UPCs / units of measure)';
    }

    public function fields(): array
    {
        $f = [
            'sku' => ['label' => 'SKU', 'required' => true, 'example' => 'MARL-RED-KS'],
            'upc' => ['label' => 'UPC (selling unit)', 'example' => '028200003577'],
            'name' => ['label' => 'Name', 'required' => true, 'example' => 'Marlboro Red Kings'],
            'brand' => ['label' => 'Brand'], 'manufacturer' => ['label' => 'Manufacturer'], 'category' => ['label' => 'Category'],
            'tax_class' => ['label' => 'Tax class', 'example' => 'cigarette_stamped', 'help' => implode(', ', array_map(fn ($c) => $c->value, TaxClass::cases()))],
            'cost' => ['label' => 'Cost'], 'price' => ['label' => 'List price'],
            'base_unit_name' => ['label' => 'Base unit name', 'example' => 'Pack'], 'units_per_case' => ['label' => 'Units per case'],
            'reorder_point' => ['label' => 'Reorder point'], 'active' => ['label' => 'Active (yes/no)'],
            'extra_upcs' => ['label' => 'Extra UPCs for the base unit (separate with |)'],
            'vendor_code' => ['label' => 'Preferred vendor code'], 'vendor_item_no' => ['label' => 'Vendor item #'],
        ];
        for ($i = 1; $i <= self::UOMS; $i++) {
            $f["uom{$i}_code"] = ['label' => "Unit {$i} code", 'example' => $i === 1 ? 'CT' : ''];
            $f["uom{$i}_factor"] = ['label' => "Unit {$i} base units", 'example' => $i === 1 ? '10' : ''];
            $f["uom{$i}_upc"] = ['label' => "Unit {$i} barcode"];
        }

        return $f;
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['sku' => 'SKU'], $e);
        $sku = static::s($v, 'sku');
        $p = $sku ? Product::whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->first() : null;
        if (! $p && static::s($v, 'name') === null) {
            $e[] = 'Name is required for a new product.';
        }
        $upc = static::s($v, 'upc');
        if ($upc !== null && ! preg_match('/^\d{6,14}$/', $upc)) {
            $e[] = "UPC \"{$upc}\" must be 6-14 digits.";
        }
        $codes = array_values(array_filter(array_map('trim', explode('|', (string) static::s($v, 'extra_upcs')))));
        $units = [];
        for ($i = 1; $i <= self::UOMS; $i++) {
            $uc = static::s($v, "uom{$i}_code");
            $fac = static::num($v, "uom{$i}_factor", $e, "Unit {$i} base units");
            $ub = static::s($v, "uom{$i}_upc");
            if ($uc === null && $fac === null && $ub === null) {
                continue;
            }
            if ($uc === null || $fac === null || (float) $fac <= 0) {
                $e[] = "Unit {$i}: code and a base-unit count above 0 are both required.";

                continue;
            }
            $units[] = ['code' => strtoupper(mb_substr($uc, 0, 20)), 'factor' => $fac, 'upc' => $ub];
            if ($ub) {
                $codes[] = $ub;
            }
        }
        foreach (array_merge($upc ? [$upc] : [], $codes) as $code) {
            $other = Product::where('upc', $code)->when($p, fn ($q) => $q->where('id', '!=', $p->id))->value('sku')
                ?? ProductBarcode::where('code', $code)->when($p, fn ($q) => $q->where('product_id', '!=', $p->id))->with('product')->first()?->product?->sku;
            if ($other) {
                $e[] = "Barcode {$code} already belongs to {$other}.";
            }
        }
        $tc = static::s($v, 'tax_class');
        if ($tc !== null && ! TaxClass::tryFrom(strtolower($tc))) {
            $e[] = "Tax class \"{$tc}\" is not one of: ".implode(', ', array_map(fn ($c) => $c->value, TaxClass::cases())).'.';
        }
        $vendor = null;
        if ($vc = static::s($v, 'vendor_code')) {
            $vendor = Vendor::where('code', $vc)->first();
            if (! $vendor) {
                $e[] = "Vendor \"{$vc}\" does not exist.";
            }
        }
        if (static::s($v, 'vendor_item_no') && ! $vendor) {
            $e[] = 'Vendor item # needs a vendor code.';
        }
        $upcCase = static::num($v, 'units_per_case', $e, 'Units per case');
        $attrs = ['upc' => $upc, 'name' => static::s($v, 'name'), 'brand' => static::s($v, 'brand'), 'manufacturer' => static::s($v, 'manufacturer'),
            'category' => static::s($v, 'category'), 'tax_class' => $tc ? strtolower($tc) : null, 'cost' => static::num($v, 'cost', $e, 'Cost'),
            'price' => static::num($v, 'price', $e, 'List price'), 'base_unit_name' => static::s($v, 'base_unit_name'),
            'units_per_case' => $upcCase !== null ? (int) $upcCase : null, 'reorder_point' => static::num($v, 'reorder_point', $e, 'Reorder point'),
            'active' => static::bool($v, 'active', $e), 'preferred_vendor_id' => $vendor?->id];
        $diff = static::diff($p, $attrs);
        $existingCodes = $p ? ProductBarcode::where('product_id', $p->id)->pluck('code')->all() : [];
        if ($new = array_values(array_diff($codes, $existingCodes))) {
            $diff['barcodes'] = [null, implode('|', $new)];
        }
        $existingUnits = $p ? ProductUnit::where('product_id', $p->id)->pluck('factor', 'code')->map(fn ($f) => (float) $f)->all() : [];
        foreach ($units as $u) {
            if (($existingUnits[$u['code']] ?? null) !== (float) $u['factor']) {
                $diff["unit {$u['code']}"] = [$existingUnits[$u['code']] ?? null, $u['factor']];
            }
        }

        return new RowResult($e, $p ? ($diff ? 'update' : 'unchanged') : 'create', $diff, $sku ? mb_strtoupper($sku) : null,
            ['model' => $p, 'sku' => $sku, 'attrs' => $attrs, 'codes' => $codes, 'units' => $units, 'vendor' => $vendor, 'vendor_item_no' => static::s($v, 'vendor_item_no')]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $c = $r->context;
        $attrs = static::filled($c['attrs']);
        $p = $c['model'];
        $p ? $p->update($attrs) : ($p = Product::create(['sku' => $c['sku']] + $attrs + ['cost' => 0, 'price' => 0]));
        $unitIds = [];
        foreach ($c['units'] as $u) {
            $unit = ProductUnit::updateOrCreate(['product_id' => $p->id, 'code' => $u['code']], ['name' => $u['code'], 'factor' => $u['factor']]);
            if ($u['upc']) {
                $unitIds[$u['upc']] = $unit->id;
            }
        }
        foreach ($c['codes'] as $code) {
            ProductBarcode::firstOrCreate(['code' => $code], ['product_id' => $p->id, 'product_unit_id' => $unitIds[$code] ?? null, 'note' => 'import #'.$b->id]);
        }
        if ($c['vendor'] && $c['vendor_item_no']) {
            VendorItem::updateOrCreate(['vendor_id' => $c['vendor']->id, 'vendor_item_no' => $c['vendor_item_no']], ['product_id' => $p->id]);
        }

        return $r->action;
    }
}
