<?php

namespace App\Support;

use App\Models\Product;
use App\Models\VendorItem;
use App\Warehouse\ProductLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 0.20.13: product lookup for admin forms (special prices, etc.).
 * Matches SKU, name, barcode / UPC, and vendor item number.
 */
final class ProductSearch
{
    /** Options for a Filament searchable Select: id => "SKU Name (list $x)". */
    public static function options(string $search, int $limit = 30): array
    {
        return self::query($search)->limit($limit)->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => self::label($p)])
            ->all();
    }

    public static function label(Product $p): string
    {
        return "{$p->sku} {$p->name} (list \${$p->price})";
    }

    public static function basePriceLabel(?Product $p): string
    {
        if (! $p) {
            return 'Select a product to see its list price.';
        }

        return '$'.number_format((float) $p->price, 2).' (list)';
    }

    /** Active, not-archived products matching the term (SKU / name / UPC / barcode / vendor item #). */
    public static function query(string $search): Builder
    {
        $term = trim($search);
        $q = Product::query()->where('active', true)->notArchived()->orderBy('name')->orderBy('id');
        if ($term === '') {
            return $q;
        }

        $like = '%'.$term.'%';
        $vars = ProductLookup::variants($term);
        $idsFromVendor = VendorItem::query()->where('vendor_item_no', 'like', $like)->limit(200)->pluck('product_id');

        return $q->where(function ($w) use ($like, $vars, $idsFromVendor, $term) {
            $w->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhereIn('upc', $vars)
                ->orWhereIn('msa_upc', $vars)
                ->orWhereHas('barcodes', fn ($b) => $b->whereIn('code', $vars)->orWhere('code', 'like', $like));
            if ($idsFromVendor->isNotEmpty()) {
                $w->orWhereIn('id', $idsFromVendor);
            }
            // Exact vendor item # (case-insensitive) when the like path missed due to limit
            if (strlen($term) >= 2) {
                $w->orWhereIn('id', VendorItem::query()->whereRaw('LOWER(vendor_item_no) = ?', [mb_strtolower($term)])->select('product_id'));
            }
        });
    }

    /** Browse catalog (POS-style): department, vendor (via vendor_items), free-text filter. */
    public static function browseQuery(?int $departmentId = null, ?int $vendorId = null, string $filter = ''): Builder
    {
        $f = trim($filter);

        return Product::query()->where('active', true)->notArchived()
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($vendorId, fn ($q) => $q->where(function ($w) use ($vendorId) {
                $w->where('preferred_vendor_id', $vendorId)
                    ->orWhereHas('vendorItems', fn ($v) => $v->where('vendor_id', $vendorId));
            }))
            ->when($f !== '', fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', "%{$f}%")
                    ->orWhere('sku', 'like', "%{$f}%")
                    ->orWhere('upc', $f)
                    ->orWhere('brand', 'like', "%{$f}%")
                    ->orWhereHas('vendorItems', fn ($v) => $v->where('vendor_item_no', 'like', "%{$f}%"));
            }))
            ->orderBy('name')->orderBy('id');
    }

    /** @return Collection<int, Product> */
    public static function search(string $term, int $limit = 30): Collection
    {
        return self::query($term)->limit($limit)->get();
    }
}
