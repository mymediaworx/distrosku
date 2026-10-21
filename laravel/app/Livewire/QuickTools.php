<?php

namespace App\Livewire;

use App\Catalog\CatalogService;
use App\Catalog\ImageService;
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
            'departments' => $company->suspended ? collect() : Department::where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ])->layout('layouts.quick', ['company' => $company]);
    }
}
