<?php

namespace App\Livewire;

use App\Models\Department;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Pos\SaleScan;
use App\Support\ProductSearch;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * 0.20.17: THE standard product picker. One Livewire component used everywhere a product is chosen
 * (sales order add-products, customer special prices, purchase orders, products & departments catalog search).
 *
 * Features (same behaviour as the POS lookup): type-ahead search by name / SKU / barcode / vendor item #,
 * scan field (USB wedge or camera), browse by department / vendor, thumbnails, multi-select.
 *
 * Usage:
 *   <livewire:product-picker wire:model="data.product_ids" :multi="true" />   // value-bound (arrays of ids)
 *   <livewire:product-picker mode="open" />                                   // catalog search: result opens the product page
 *   Filament: App\Filament\Forms\Components\ProductPickerField::make('product_ids')->multiple()
 *
 * Props: multi (bool), mode ('select' | 'open'), label (string), showCost (bool), wire:model (ids).
 * Events (dispatched on every change): `product-picker-changed` { ids: int[], id: ?int, name: string }.
 */
class ProductPicker extends Component
{
    public const BROWSE_PAGE = 60;

    /** int|null in single mode, list<int> in multi mode (bind with wire:model). */
    #[Modelable]
    public mixed $value = null;

    public bool $multi = false;

    /** 'select' = bind to value; 'open' = result opens the product page (catalog search). */
    public string $mode = 'select';

    public string $label = 'Product';

    public bool $showCost = false;

    public string $name = 'product-picker';

    public string $search = '';

    public string $scan = '';

    public bool $browsing = false;

    public string $browseFilter = '';

    public ?int $browseDepartment = null;

    public ?int $browseVendor = null;

    public int $browseLimit = self::BROWSE_PAGE;

    public function mount(): void
    {
        $this->value = $this->normalise($this->value);
    }

    /** @return list<int> */
    public function ids(): array
    {
        $v = $this->value;
        if ($v === null || $v === '' || $v === []) {
            return [];
        }

        return array_values(array_unique(array_map('intval', (array) $v)));
    }

    private function normalise(mixed $v): mixed
    {
        $ids = $this->ids();
        if ($this->multi) {
            return $ids;
        }

        return $ids[0] ?? null;
    }

    private function setIds(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $this->multi) {
            $ids = array_slice($ids, 0, 1);
        }
        $this->value = $this->multi ? $ids : ($ids[0] ?? null);
        $this->dispatch('product-picker-changed', ids: $ids, id: $ids[0] ?? null, name: $this->name);
    }

    public function add(int $id): void
    {
        if ($this->mode === 'open') {
            return;
        }
        $this->setIds($this->multi ? [...$this->ids(), $id] : [$id]);
        $this->search = '';
    }

    public function toggle(int $id): void
    {
        if (in_array($id, $this->ids(), true)) {
            $this->remove($id);

            return;
        }
        $this->add($id);
        if (! $this->multi) {
            $this->browsing = false;
        }
    }

    public function remove(int $id): void
    {
        $this->setIds(array_filter($this->ids(), fn ($x) => $x !== $id));
    }

    public function clear(): void
    {
        $this->setIds([]);
    }

    /** Scan field (Enter): barcode / UPC / SKU first, then the broader search when it narrows to exactly one. */
    public function applyScan(): void
    {
        $term = trim($this->scan);
        $this->scan = '';
        if ($term === '') {
            return;
        }
        $product = (SaleScan::resolve($term)['product'] ?? null);
        if (! $product) {
            $matches = ProductSearch::query($term)->limit(2)->get();
            $product = $matches->count() === 1 ? $matches->first() : null;
        }
        if (! $product) {
            $this->dispatch('product-picker-scan-miss', term: $term);
            session()->flash('picker-miss-'.$this->getId(), "Nothing matched \"{$term}\".");

            return;
        }
        if ($this->mode === 'open') {
            $this->redirect($this->productUrl($product));

            return;
        }
        $this->add((int) $product->id);
    }

    public function openBrowse(): void
    {
        $this->browsing = true;
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function closeBrowse(): void
    {
        $this->browsing = false;
    }

    public function setDepartment(?int $id = null): void
    {
        $this->browseDepartment = $id ?: null;
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

    public function updatedBrowseFilter(): void
    {
        $this->browseLimit = self::BROWSE_PAGE;
    }

    public function loadMore(): void
    {
        $this->browseLimit = min($this->browseLimit + self::BROWSE_PAGE, max(self::BROWSE_PAGE, $this->browseTotal));
    }

    public function productUrl(Product $p): string
    {
        return \App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $p]);
    }

    #[Computed]
    public function results(): array
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return ProductSearch::query($term)->with('department')->limit(12)->get()
            ->map(fn (Product $p) => $this->card($p))->all();
    }

    #[Computed]
    public function browseTotal(): int
    {
        return $this->browsing
            ? ProductSearch::browseQuery($this->browseDepartment, $this->browseVendor, $this->browseFilter)->count()
            : 0;
    }

    #[Computed]
    public function tiles(): array
    {
        if (! $this->browsing) {
            return [];
        }

        return ProductSearch::browseQuery($this->browseDepartment, $this->browseVendor, $this->browseFilter)
            ->with('department')->limit(max(self::BROWSE_PAGE, $this->browseLimit))->get()
            ->map(fn (Product $p) => $this->card($p))->all();
    }

    #[Computed]
    public function facets(): array
    {
        if (! $this->browsing) {
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

    #[Computed]
    public function picked(): array
    {
        $ids = $this->ids();
        if ($ids === []) {
            return [];
        }

        return Product::whereIn('id', $ids)->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $ids, true))
            ->map(fn (Product $p) => $this->card($p))->values()->all();
    }

    /** @return array{id:int,sku:string,name:string,price:string,cost:string,dept:?string,image:?string,url:string} */
    private function card(Product $p): array
    {
        return [
            'id' => $p->id,
            'sku' => (string) $p->sku,
            'name' => (string) $p->name,
            'price' => number_format((float) $p->price, 2),
            'cost' => number_format((float) $p->cost, 2),
            'dept' => $p->department?->name,
            'image' => $p->imageUrl(),
            'url' => $this->productUrl($p),
        ];
    }

    public function render()
    {
        return view('livewire.product-picker');
    }
}
