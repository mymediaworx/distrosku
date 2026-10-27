<?php

namespace App\Filament\Concerns;

use App\Models\Department;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Pos\SaleScan;
use App\Support\ProductSearch;
use Filament\Notifications\Notification;

/**
 * 0.20.13: scan + browse catalog product picker for customer special prices.
 * Create pages set $productLookupMulti = true to allow multi-select (same special price on several products).
 */
trait InteractsWithSpecialPriceProductLookup
{
    public string $productScan = '';

    public bool $productBrowsing = false;

    public string $browseFilter = '';

    public ?int $browseDepartment = null;

    public ?int $browseVendor = null;

    public int $browseLimit = 60;

    /** @var list<int> */
    public array $pickedProductIds = [];

    public bool $productLookupMulti = false;

    public const BROWSE_PAGE = 60;

    public function applyProductScan(): void
    {
        $term = trim($this->productScan);
        $this->productScan = '';
        if ($term === '') {
            return;
        }
        $hit = SaleScan::resolve($term);
        $product = $hit['product'] ?? null;
        if (! $product) {
            // Vendor item # / broader search (same matches as the searchable picker).
            $matches = ProductSearch::query($term)->limit(2)->get();
            $product = $matches->count() === 1 ? $matches->first() : null;
        }
        if (! $product) {
            Notification::make()->danger()->title('No product found')->body("Nothing matched \"{$term}\".")->send();

            return;
        }
        $this->addPickedProduct((int) $product->id);
        Notification::make()->success()->title('Product added')->body($product->sku.' — '.$product->name)->send();
    }

    public function openProductBrowse(): void
    {
        $this->productBrowsing = true;
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function closeProductBrowse(): void
    {
        $this->productBrowsing = false;
    }

    public function browseDept(?int $departmentId = null): void
    {
        $this->browseDepartment = $departmentId ?: null;
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function setBrowseVendor(?int $vendorId = null): void
    {
        $this->browseVendor = $vendorId ?: null;
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function updatedBrowseFilter(): void
    {
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function updatedBrowseDepartment($value): void
    {
        $this->browseDepartment = $value !== null && $value !== '' ? (int) $value : null;
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function updatedBrowseVendor($value): void
    {
        $this->browseVendor = $value !== null && $value !== '' ? (int) $value : null;
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function loadMoreBrowse(): void
    {
        $this->browseLimit = min($this->browseLimit + self::BROWSE_PAGE, max(self::BROWSE_PAGE, $this->browseTotal));
    }

    public function toggleBrowseProduct(int $id): void
    {
        if ($this->productLookupMulti) {
            if (in_array($id, $this->pickedProductIds, true)) {
                $this->pickedProductIds = array_values(array_filter($this->pickedProductIds, fn ($x) => $x !== $id));
            } else {
                $this->pickedProductIds[] = $id;
            }
            $this->syncPickedToForm();

            return;
        }
        $this->pickedProductIds = [$id];
        $this->syncPickedToForm();
        $this->closeProductBrowse();
    }

    public function selectBrowseProduct(int $id): void
    {
        $this->addPickedProduct($id);
        if (! $this->productLookupMulti) {
            $this->closeProductBrowse();
        }
    }

    public function removePickedProduct(int $id): void
    {
        $this->pickedProductIds = array_values(array_filter($this->pickedProductIds, fn ($x) => (int) $x !== (int) $id));
        $this->syncPickedToForm();
    }

    public function clearPickedProducts(): void
    {
        $this->pickedProductIds = [];
        $this->syncPickedToForm();
    }

    public function confirmBrowseSelection(): void
    {
        $this->syncPickedToForm();
        $this->closeProductBrowse();
        $n = count($this->pickedProductIds);
        if ($n > 0) {
            Notification::make()->success()->title($n === 1 ? '1 product selected' : "{$n} products selected")->send();
        }
    }

    protected function addPickedProduct(int $id): void
    {
        if ($this->productLookupMulti) {
            if (! in_array($id, $this->pickedProductIds, true)) {
                $this->pickedProductIds[] = $id;
            }
        } else {
            $this->pickedProductIds = [$id];
        }
        $this->syncPickedToForm();
    }

    public function syncPickedToForm(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->pickedProductIds)));
        $this->pickedProductIds = $ids;
        $state = $this->form->getRawState();
        if ($this->productLookupMulti) {
            $state['product_ids'] = $ids;
            $state['product_id'] = $ids[0] ?? null;
        } else {
            $state['product_id'] = $ids[0] ?? null;
        }
        $this->form->fill($state);
    }

    /** Seed picker from an existing record (edit). */
    protected function seedPickedFromRecord(): void
    {
        $id = $this->record->product_id ?? null;
        $this->pickedProductIds = $id ? [(int) $id] : [];
    }

    public function getBrowseTotalProperty(): int
    {
        return $this->productBrowsing
            ? ProductSearch::browseQuery($this->browseDepartment, $this->browseVendor, $this->browseFilter)->count()
            : 0;
    }

    /** @return list<array{id:int,sku:string,name:string,price:string,dept:?string}> */
    public function getBrowseTilesProperty(): array
    {
        if (! $this->productBrowsing) {
            return [];
        }
        $ps = ProductSearch::browseQuery($this->browseDepartment, $this->browseVendor, $this->browseFilter)
            ->with('department')
            ->limit(max(self::BROWSE_PAGE, $this->browseLimit))
            ->get();

        return $ps->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'price' => number_format((float) $p->price, 2),
            'dept' => $p->department?->name,
        ])->all();
    }

    /** @return array{departments: array<int,string>, vendors: array<int,string>} */
    public function getBrowseFacetsProperty(): array
    {
        if (! $this->productBrowsing) {
            return ['departments' => [], 'vendors' => []];
        }
        $base = Product::query()->where('active', true)->notArchived();

        return [
            'departments' => Department::whereIn('id', (clone $base)->whereNotNull('department_id')->select('department_id'))
                ->orderBy('name')->pluck('name', 'id')->all(),
            'vendors' => Vendor::query()->where(function ($w) use ($base) {
                $w->whereIn('id', (clone $base)->whereNotNull('preferred_vendor_id')->select('preferred_vendor_id'))
                    ->orWhereIn('id', VendorItem::query()->whereIn('product_id', (clone $base)->select('id'))->select('vendor_id'));
            })->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** @return list<array{id:int,sku:string,name:string,price:string}> */
    public function getPickedProductsProperty(): array
    {
        if ($this->pickedProductIds === []) {
            return [];
        }

        return Product::whereIn('id', $this->pickedProductIds)->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $this->pickedProductIds, true))
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'price' => number_format((float) $p->price, 2),
            ])->values()->all();
    }

    public function getPrimaryBasePriceLabelProperty(): string
    {
        $id = $this->pickedProductIds[0] ?? null;
        if (! $id) {
            return ProductSearch::basePriceLabel(null);
        }

        return ProductSearch::basePriceLabel(Product::find($id));
    }
}
