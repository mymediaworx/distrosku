<?php

namespace App\Livewire;

use App\Catalog\CatalogService;
use App\Catalog\ImageService;
use App\Catalog\SkuApproval;
use App\Inventory\QuickCountService;
use App\Models\Company;
use App\Models\Department;
use App\Models\Product;
use App\Models\User;
use App\Pos\PosPin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Public employee page /q/{token}: create SKU, lookup, print labels, counts. No login; token + rate limits; no customer/financial data.
 * #45: creating a SKU and submitting a count need the employee's 4-digit PIN (the same PIN as the POS, set under Users). The PIN
 * names who did it; new SKUs wait for an owner/admin to approve them (Products > New SKUs to approve) before they can be sold.
 */
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

    /** #45: PIN typed to unlock create / count (never kept after the check). */
    public string $pin = '';

    public const STAFF_SESSION = 'quick_staff';

    public const STAFF_IDLE_SECONDS = 900;

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

    /** #45: the employee who unlocked this page with their PIN, if still within the idle window. */
    public function staff(): ?User
    {
        $s = session(self::STAFF_SESSION);
        if (! is_array($s) || ! isset($s['id'], $s['at']) || now()->timestamp - (int) $s['at'] > self::STAFF_IDLE_SECONDS) {
            return null;
        }
        $u = User::find($s['id']);
        if (! $u || ! $u->active) {
            return null;
        }
        session([self::STAFF_SESSION => ['id' => $u->id, 'at' => now()->timestamp]]);

        return $u;
    }

    public function unlock(): void
    {
        $pin = $this->pin;
        $this->pin = '';
        if ($this->guard()->suspended || ! $this->limit('pin', 10)) {
            return;
        }
        $u = PosPin::valid($pin) ? User::where('pos_pin_lookup', PosPin::lookup($pin))->first() : null;
        if (! $u || ! $u->active || ! $u->pos_pin_hash || ! Hash::check($pin, $u->pos_pin_hash)) {
            app(PosPin::class)->log($u, 'quick_tools_failed');
            $this->addError('pin', 'Wrong PIN. Ask a manager to set your PIN under Users.');

            return;
        }
        session([self::STAFF_SESSION => ['id' => $u->id, 'at' => now()->timestamp]]);
        app(PosPin::class)->log($u, 'quick_tools_unlock');
    }

    public function lock(): void
    {
        session()->forget(self::STAFF_SESSION);
    }

    private function requireStaff(): ?User
    {
        $u = $this->staff();
        if (! $u) {
            $this->addError('pin', 'Enter your PIN first.');
        }

        return $u;
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
        if (! ($staff = $this->requireStaff())) {
            return;
        }
        $data = $this->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0|max:999999',
            'upc' => 'nullable|string|max:20|regex:/^[0-9A-Za-z\- ]*$/',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp,gif|mimetypes:image/jpeg,image/png,image/webp,image/gif|max:'.ImageService::MAX_KB,
        ]);
        if ($dup = SkuApproval::duplicateUpc($data['upc'] ?? null)) { // #45: one UPC, one product
            $this->addError('upc', "That UPC is already on {$dup->sku} {$dup->name}. Use Lookup to find it.");

            return;
        }
        try {
            $p = $catalog->create(array_diff_key($data, ['tax_class' => 1]), $this->image, 'quick_tools'); // tax class: set by the approver
            $p = app(SkuApproval::class)->markPending($p, $staff);
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
        if (! ($staff = $this->requireStaff())) {
            return;
        }
        $this->count_by = $staff->name; // #45: the PIN names who counted
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
            'dept' => $p->department?->name, 'img' => $p->imageUrl(), 'pending' => $p->isPendingApproval()];
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
            'staff' => $company->suspended ? null : $this->staff(),
            'warehouses' => $company->suspended ? collect() : \App\Models\Warehouse::where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => $company->suspended ? collect() : Department::where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ])->layout('layouts.quick', ['company' => $company]);
    }
}
