<?php

namespace App\Livewire;

use App\Catalog\CatalogService;
use App\Catalog\ImageService;
use App\Inventory\QuickCountService;
use App\Models\Company;
use App\Models\Department;
use App\Models\Product;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Public employee page /q/{token}: create SKU, lookup, print labels. No login; token + rate limits; no customer/financial data. */
class QuickTools extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $token = '';

    public string $tab = 'create';

    public ?int $department_id = null;

    public string $name = '';

    public string $price = '';

    public string $upc = '';

    public $image = null;

    public string $q = '';

    public array $results = [];

    public ?array $created = null;

    // 0.20.20 quick counts: find an item, type what is on the shelf; an admin reviews before any stock changes
    public string $cq = '';

    public array $countResults = [];

    public ?int $count_product_id = null;

    public ?int $count_warehouse_id = null;

    public string $count_qty = '';

    public string $count_by = '';

    public string $count_note = '';

    public ?string $countDone = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->guard();
    }

    private function guard(): Company
    {
        $c = Company::current();
        abort_unless($c->public_token && hash_equals($c->public_token, $this->token), 404);

        return $c;
    }

    private function limit(string $what, int $max): bool
    {
        $key = "quick:{$what}:".request()->ip();
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->addError('rate', 'Too many requests. Wait a minute and try again.');

            return false;
        }
        RateLimiter::hit($key, 60);

        return true;
    }

    public function create(CatalogService $catalog): void
    {
        if ($this->guard()->suspended || ! $this->limit('create', 20)) {
            return;
        }
        $data = $this->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0|max:999999',
            'upc' => 'nullable|string|max:20|regex:/^[0-9A-Za-z\- ]*$/',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif|mimetypes:image/jpeg,image/png,image/webp,image/gif|max:'.ImageService::MAX_KB,
        ]);
        try {
            $p = $catalog->create($data, $this->image, 'quick_tools');
        } catch (\Throwable $e) {
            $this->addError('name', $e->getMessage());

            return;
        }
        $this->created = $this->row($p);
        $this->reset(['name', 'price', 'upc', 'image']);
    }

    public function search(CatalogService $catalog): void
    {
        if ($this->guard()->suspended || ! $this->limit('lookup', 60)) {
            return;
        }
        $this->validate(['q' => 'required|string|max:100']);
        $this->results = $catalog->lookup($this->q)->map(fn ($p) => $this->row($p))->all();
    }

    public function countSearch(CatalogService $catalog): void
    {
        if ($this->guard()->suspended || ! $this->limit('lookup', 60)) {
            return;
        }
        $this->validate(['cq' => 'required|string|max:100']);
        $this->countResults = $catalog->lookup($this->cq, 10)->map(fn ($p) => $this->row($p))->all();
        $this->count_product_id = count($this->countResults) === 1 ? $this->countResults[0]['id'] : null; // one hit = already picked (scanner flow)
        $this->countDone = null;
    }

    public function pickCount(int $id): void
    {
        $this->count_product_id = collect($this->countResults)->contains('id', $id) ? $id : null;
    }

    /** Records the count only. Nothing here changes stock; it lands on the Quick counts page for an admin to apply. */
    public function submitCount(QuickCountService $svc): void
    {
        if ($this->guard()->suspended || ! $this->limit('count', 60)) {
            return;
        }
        $this->validate([
            'count_product_id' => 'required|integer|exists:products,id',
            'count_warehouse_id' => 'required|integer|exists:warehouses,id',
            'count_qty' => 'required|numeric|min:0|max:9999999',
            'count_by' => 'required|string|max:100',
            'count_note' => 'nullable|string|max:255',
        ], [], ['count_product_id' => 'item', 'count_warehouse_id' => 'location', 'count_qty' => 'quantity', 'count_by' => 'your name']);
        $q = $svc->submit($this->count_product_id, $this->count_warehouse_id, $this->count_qty, $this->count_by, $this->count_note);
        $this->countDone = "Saved: {$q->product->sku} counted {$this->count_qty}. A manager will review it before stock changes.";
        $this->reset(['cq', 'countResults', 'count_product_id', 'count_qty', 'count_note']);
    }

    private function row(Product $p): array
    {
        return ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'price' => number_format((float) $p->price, 2), 'upc' => $p->upc,
            'dept' => $p->department?->name, 'img' => $p->imageUrl()];
    }

    public function labelUrl(array $ids): string
    {
        return route('quick-tools.labels', ['token' => $this->token, 'ids' => implode(',', array_map('intval', $ids))]);
    }

    public function render()
    {
        $company = Company::current();

        return view('livewire.quick-tools', [
            'company' => $company,
            'warehouses' => $company->suspended ? collect() : \App\Models\Warehouse::where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => $company->suspended ? collect() : Department::where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ])->layout('layouts.quick', ['company' => $company]);
    }
}
