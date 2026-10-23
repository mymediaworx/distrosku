<?php

namespace App\Livewire;

use App\Delivery\SignatureService;
use App\Http\Middleware\PosAccess;
use App\Livewire\Concerns\SignaturePad;
use App\Audit\Audit;
use App\Credit\CreditLimit;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\PosHeldSale;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\Warehouse;
use App\Pos\PosPin;
use App\Pricing\PriceQuote;
use App\Pricing\PricingEngine;
use App\Services\ComplianceChecker;
use App\Services\Money;
use App\Services\SalesOrderService;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.pos')]
#[Title('Counter POS')]
class CounterPos extends Component
{
    use SignaturePad;

    public ?int $customerId = null;

    public ?int $shipToId = null;

    /** 0.19.6: every sale is counter pickup unless the cashier picks Delivery (then a ship-to is needed). */
    public string $fulfillment = 'pickup';

    public ?int $warehouseId = null;

    public string $scan = '';

    /** @var array<int, array{product_id:int, sku:string, name:string, qty:string, price:string, reasons:array}> */
    public array $cart = [];

    public string $tender = 'cash';

    /** Cash tendered (keypad), for change due. */
    public string $tendered = '';

    public ?string $message = null;

    public ?string $error = null;

    /** 0.19.3 */
    public string $notes = '';

    public bool $browsing = false;

    public string $browseFilter = '';

    public ?string $browseCategory = null;

    public ?string $browseBrand = null;

    public ?int $pickProductId = null;

    public string $pickQty = '1';

    /** The first digit typed replaces the starting 1. */
    public bool $pickFresh = true;

    public bool $holding = false;

    public string $holdLabel = '';

    public bool $recalling = false;

    /** @var list<string> warnings shown after a recall (price changes, stock, credit) */
    public array $recallNotes = [];

    /** #27: last completed sale on this screen, for "Print receipt" (and the cash tendered, for change due). */
    public ?int $lastSaleId = null;

    public ?string $lastTendered = null;

    public function mount(): void
    {
        $cash = Customer::where('code', 'CASH')->first() ?? Customer::first();
        $this->customerId = $cash?->id;
        $this->shipToId = null;
        $this->warehouseId = Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id');
        // 0.14.1: an in-progress sale survives the idle lock / employee switch (kept in this terminal's session).
        if ($held = session('pos_cart')) {
            $this->cart = $held['cart'] ?? [];
            $this->customerId = $held['customerId'] ?? $this->customerId;
            $this->fulfillment = ($held['fulfillment'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
            $this->shipToId = $this->fulfillment === 'delivery' ? Customer::find($this->customerId)?->resolveShipToId($held['shipToId'] ?? null) : null;
            $this->tender = $held['tender'] ?? $this->tender;
            $this->notes = $held['notes'] ?? '';
            if ($this->cart) {
                $this->reprice();
                $this->recheck();
            }
        }
    }

    /** Qty for display in the stepper: 2.0000 -> 2, 1.5000 -> 1.5. */
    private static function q(mixed $v): string
    {
        $s = Money::s($v);

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    public function dehydrate(): void
    {
        session()->put('pos_cart', ['cart' => $this->cart, 'customerId' => $this->customerId, 'shipToId' => $this->shipToId, 'fulfillment' => $this->fulfillment, 'tender' => $this->tender, 'notes' => $this->notes]);
    }

    public function inc(int $i): void
    {
        if (isset($this->cart[$i])) {
            $this->cart[$i]['qty'] = self::q(Money::add($this->cart[$i]['qty'] ?: 0, 1));
            $this->reprice();
            $this->recheck();
        }
    }

    public function dec(int $i): void
    {
        if (! isset($this->cart[$i])) {
            return;
        }
        $q = Money::of($this->cart[$i]['qty'] ?: 0)->minus(1);
        if (! $q->isPositive()) {
            $this->remove($i);

            return;
        }
        $this->cart[$i]['qty'] = self::q($q);
        $this->reprice();
        $this->recheck();
    }

    /** Lock / switch employee. The cart stays for the next PIN login on this terminal. */
    public function lock()
    {
        $this->dehydrate();
        PosAccess::lock(request());

        return $this->redirectRoute('pos.lock');
    }

    public function getChangeDueProperty(): ?string
    {
        if ($this->tender !== 'cash' || $this->tendered === '' || ! is_numeric($this->tendered)) {
            return null;
        }

        return Money::of($this->tendered)->minus(Money::of($this->total))->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    /** 0.19.6: Delivery prefills the customer's default ship-to; Counter pickup carries none. */
    public function updatedFulfillment(): void
    {
        $this->fulfillment = $this->fulfillment === 'delivery' ? 'delivery' : 'pickup';
        $this->shipToId = $this->fulfillment === 'delivery' ? Customer::find($this->customerId)?->resolveShipToId($this->shipToId) : null;
        $this->recheck();
    }

    public function updatedCustomerId(): void
    {
        $this->shipToId = $this->fulfillment === 'delivery' ? Customer::find($this->customerId)?->resolveShipToId() : null;
        $this->reprice(true);
        $this->recheck();
    }

    public function updatedShipToId(): void
    {
        $this->shipToId = $this->fulfillment === 'delivery' ? Customer::find($this->customerId)?->resolveShipToId($this->shipToId ?: null) : null;
        $this->recheck();
    }

    public function add(): void
    {
        $this->reset('message', 'error');
        $term = trim($this->scan);
        if ($term === '') {
            return;
        }
        $hit = \App\Pos\SaleScan::resolve($term); // #25: case / carton / pack barcodes all work
        if (! $hit) {
            $this->error = "No product for \"{$term}\".";

            return;
        }
        $this->addProduct($hit['product'], $hit['qty']);
        $this->message = \App\Pos\SaleScan::addedMessage($hit);
        $this->scan = '';
    }

    /** Shared by scan/search and Browse: same pricing and eligibility checks. */
    private function addProduct(Product $p, string $qty): void
    {
        foreach ($this->cart as $i => $line) {
            if ($line['product_id'] === $p->id) {
                $this->cart[$i]['qty'] = self::q(Money::add($line['qty'], $qty));
                $this->reprice();
                $this->recheck();

                return;
            }
        }
        $q = $this->quote($p, $qty);
        $this->cart[] = ['product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'qty' => self::q($qty), 'price' => $q->price, 'quoted' => $q->price, 'source' => $q->source, 'reasons' => []];
        $this->recheck();
    }

    // ---- 0.19.3 Browse catalog -------------------------------------------------

    public function openBrowse(): void
    {
        $this->browsing = true;
        $this->pickProductId = null;
    }

    public function closeBrowse(): void
    {
        $this->browsing = false;
        $this->pickProductId = null;
    }

    public function browseBy(?string $category = null, ?string $brand = null): void
    {
        $this->browseCategory = $category;
        $this->browseBrand = $brand;
    }

    public function pick(int $id): void
    {
        $this->pickProductId = $id;
        $this->pickQty = '1';
        $this->pickFresh = true;
    }

    public function pickKey(string $k): void
    {
        if ($this->pickFresh && ctype_digit($k)) {
            $this->pickQty = '';
        }
        $this->pickFresh = false;
        if ($k === 'back') {
            $this->pickQty = substr($this->pickQty, 0, -1);
        } elseif ($k === 'clear') {
            $this->pickQty = '';
        } elseif (ctype_digit($k) && strlen($this->pickQty) < 5) {
            $this->pickQty = ltrim($this->pickQty.$k, '0') ?: '0';
        }
    }

    public function addPicked(): void
    {
        $this->reset('message', 'error');
        $p = $this->pickProductId ? $this->catalogQuery()->whereKey($this->pickProductId)->first() : null;
        $qty = (int) $this->pickQty;
        if (! $p || $qty < 1) {
            $this->error = $p ? 'Enter a quantity of 1 or more.' : 'Product not available.';

            return;
        }
        $this->addProduct($p, (string) $qty);
        $this->pickProductId = null;
        $this->message = "Added {$qty} × {$p->name}.";
    }

    private function catalogQuery()
    {
        return Product::where('active', true)->notArchived();
    }

    /** Tiles for the browser: name, unit, price for the selected customer, stock in this warehouse. */
    public function getBrowseTilesProperty(): array
    {
        if (! $this->browsing) {
            return [];
        }
        $f = trim($this->browseFilter);
        $ps = $this->catalogQuery()
            ->when($this->browseCategory, fn ($q) => $q->where('category', $this->browseCategory))
            ->when($this->browseBrand, fn ($q) => $q->where('brand', $this->browseBrand))
            ->when($f !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$f}%")->orWhere('sku', 'like', "%{$f}%")->orWhere('upc', $f)->orWhere('brand', 'like', "%{$f}%")))
            ->orderBy('name')->limit(60)->get();
        $stock = Inventory::where('warehouse_id', $this->warehouseId)->whereIn('product_id', $ps->pluck('id'))->get()->keyBy('product_id');
        $c = Customer::find($this->customerId);

        return $ps->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'brand' => $p->brand,
            'price' => app(PricingEngine::class)->quote($p, $c, 1)->price,
            'stock' => isset($stock[$p->id]) ? self::q(Money::of($stock[$p->id]->qty)->minus(Money::of($stock[$p->id]->reserved))) : '0'])->all();
    }

    public function getBrowseFacetsProperty(): array
    {
        if (! $this->browsing) {
            return ['categories' => [], 'brands' => []];
        }
        $q = $this->catalogQuery();

        return ['categories' => (clone $q)->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category')->all(),
            'brands' => (clone $q)->whereNotNull('brand')->where('brand', '!=', '')->when($this->browseCategory, fn ($w) => $w->where('category', $this->browseCategory))
                ->distinct()->orderBy('brand')->pluck('brand')->all()];
    }

    // ---- 0.19.3 Hold / Recall ----------------------------------------------------

    public function hold(): void
    {
        $this->reset('message', 'error');
        if (! $this->cart) {
            $this->error = 'Cart is empty: nothing to hold.';

            return;
        }
        $h = PosHeldSale::create([
            'label' => trim($this->holdLabel) ?: null, 'customer_id' => $this->customerId, 'ship_to_id' => $this->shipToId,
            'warehouse_id' => $this->warehouseId, 'user_id' => auth()->id(), 'register' => self::register(), 'tender' => $this->tender,
            'notes' => trim($this->notes) ?: null, 'total' => Money::s($this->total),
            'cart' => array_map(fn ($l) => array_intersect_key($l, array_flip(['product_id', 'sku', 'name', 'qty', 'price', 'quoted', 'source'])), $this->cart),
            'expires_at' => now()->addDays(PosHeldSale::days()),
        ]);
        Audit::action('pos_hold', null, 'POS sale held: '.($h->label ?: '#'.$h->id), ['held_id' => $h->id, 'customer_id' => $h->customer_id, 'lines' => count($h->cart), 'total' => $h->total]);
        $this->clearSale();
        $this->holding = false;
        $this->holdLabel = '';
        $this->message = 'Sale held'.($h->label ? " as \"{$h->label}\"" : '').'. No stock is reserved. Recall it from "Recall".';
    }

    private static function register(): string
    {
        return mb_substr((string) (session('pos_register') ?: request()->ip()), 0, 80);
    }

    private function clearSale(): void
    {
        $this->cart = [];
        $this->tendered = '';
        $this->notes = '';
        $this->recallNotes = [];
        $this->resetSignature();
        $cash = Customer::where('code', 'CASH')->first() ?? Customer::first();
        $this->customerId = $cash?->id;
        $this->shipToId = null;
        $this->fulfillment = 'pickup';
    }

    public function getHeldSalesProperty()
    {
        if (! $this->recalling) {
            return collect();
        }
        PosHeldSale::purgeExpired();

        return PosHeldSale::live()->with(['customer:id,name', 'user:id,name'])->where('warehouse_id', $this->warehouseId)->latest()->limit(50)->get();
    }

    public function recall(int $id): void
    {
        $this->reset('message', 'error');
        $h = PosHeldSale::live()->where('warehouse_id', $this->warehouseId)->find($id);
        if (! $h) {
            $this->error = 'That held sale is gone (recalled, deleted or expired).';

            return;
        }
        if ($this->cart) {
            $this->error = 'Finish, hold or clear the current sale first.';

            return;
        }
        $this->customerId = $h->customer_id ?? $this->customerId;
        $this->fulfillment = $h->ship_to_id ? 'delivery' : 'pickup'; // 0.19.6: a held sale with a ship-to was a delivery
        $this->shipToId = $this->fulfillment === 'delivery' ? Customer::find($this->customerId)?->resolveShipToId($h->ship_to_id) : null;
        $this->tender = $h->tender ?: $this->tender;
        $this->notes = (string) $h->notes;
        $this->cart = array_map(fn ($l) => $l + ['reasons' => []], array_values(array_filter($h->cart ?? [], fn ($l) => Product::whereKey($l['product_id'] ?? 0)->where('active', true)->exists())));
        $notes = [];
        if (count($this->cart) < count($h->cart ?? [])) {
            $notes[] = 'Some items are no longer active and were left out.';
        }
        $before = collect($this->cart)->mapWithKeys(fn ($l) => [$l['product_id'] => $l['price']])->all();
        $this->reprice();
        $this->recheck();
        foreach ($this->cart as $l) {
            if (isset($before[$l['product_id']]) && ! Money::of($before[$l['product_id']])->isEqualTo(Money::of($l['price']))) {
                $notes[] = "Price changed: {$l['sku']} \${$before[$l['product_id']]} → \${$l['price']}.";
            }
            $inv = Inventory::where('warehouse_id', $this->warehouseId)->where('product_id', $l['product_id'])->first();
            $avail = $inv ? Money::of($inv->qty)->minus(Money::of($inv->reserved)) : Money::of(0);
            if ($avail->isLessThan(Money::of($l['qty'] ?: 0))) {
                $notes[] = "Low stock: {$l['sku']} needs {$l['qty']}, ".self::q($avail).' available.';
            }
        }
        $c = Customer::find($this->customerId);
        if ($c && $this->tender === 'account' && ($a = app(CreditLimit::class)->available($c)) !== null && Money::of($a)->isLessThan(Money::of($this->total))) {
            $notes[] = 'Credit: available $'.number_format((float) $a, 2).' is less than this sale.';
        }
        if ($this->blocked) {
            $notes[] = 'Some items are now blocked (see the lines).';
        }
        Audit::action('pos_recall', null, 'POS held sale recalled: '.($h->label ?: '#'.$h->id), ['held_id' => $h->id, 'held_by' => $h->user_id, 'changes' => $notes]);
        $h->delete();
        $this->recallNotes = $notes;
        $this->recalling = false;
        $this->message = 'Held sale recalled'.($notes ? ' with changes: check below.' : '.');
    }

    public function deleteHeld(int $id): void
    {
        if (! auth()->user()?->isAdmin()) {
            $this->error = 'Only an owner or admin can delete a held sale.';

            return;
        }
        if ($h = PosHeldSale::find($id)) {
            Audit::action('pos_hold_deleted', null, 'Held POS sale deleted: '.($h->label ?: '#'.$h->id), ['held_id' => $h->id, 'customer_id' => $h->customer_id, 'total' => $h->total]);
            $h->delete();
        }
    }

    public function remove(int $i): void
    {
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);
    }

    public function updatedCart(): void
    {
        $this->reprice();
        $this->recheck();
    }

    private function quote(Product $p, mixed $qty): PriceQuote
    {
        return app(PricingEngine::class)->quote($p, Customer::find($this->customerId), $qty);
    }

    /** Lines still at their quoted price follow the engine (qty breaks, customer change); a cashier-typed price is a logged manual override. */
    public function reprice(bool $all = false): void
    {
        foreach ($this->cart as $i => $l) {
            if (! ($p = Product::find($l['product_id']))) {
                continue;
            }
            $atQuote = $all || ! isset($l['quoted']) || Money::of($l['price'] ?: 0)->isEqualTo(Money::of($l['quoted']));
            $q = $this->quote($p, $l['qty'] ?: 1);
            if ($atQuote) {
                $this->cart[$i]['price'] = $q->price;
                $this->cart[$i]['source'] = $q->source;
            } else {
                $this->cart[$i]['source'] = 'manual';
            }
            $this->cart[$i]['quoted'] = $q->price;
        }
    }

    public function recheck(): void
    {
        $c = Customer::find($this->customerId);
        // 0.19.6: counter pickup is checked at the selling warehouse; delivery at the chosen ship-to.
        $s = $this->fulfillment === 'delivery'
            ? ShipTo::where('customer_id', $this->customerId)->find($this->shipToId)
            : SalesOrder::pickupLocation($this->warehouseId, $this->customerId);
        foreach ($this->cart as $i => $line) {
            $this->cart[$i]['reasons'] = ($c && $s && ($p = Product::find($line['product_id'])))
                ? app(ComplianceChecker::class)->checkLine($p, $c, $s) : [$c ? 'Pick a ship-to for delivery, or switch to Counter pickup.' : 'Pick a customer.'];
        }
    }

    /** Customer's account balance (open invoices + remaining beginning balance); negative = credit on account. */
    public function getCustomerBalanceProperty(): ?string
    {
        $c = $this->customerId ? Customer::find($this->customerId) : null;

        return $c ? $c->balance() : null;
    }

    public function getTotalProperty(): string
    {
        return Money::add(...(array_map(fn ($l) => Money::mul($l['qty'] ?: 0, $l['price'] ?: 0), $this->cart) ?: [0]));
    }

    public function getBlockedProperty(): bool
    {
        return collect($this->cart)->contains(fn ($l) => ! empty($l['reasons']));
    }

    public function checkout(SalesOrderService $svc): void
    {
        $this->reset('message', 'error');
        $this->recheck();
        if ($this->cart && $this->blocked) {
            \App\Audit\Audit::durable('pos_sale_blocked', null, 'POS checkout blocked by compliance', ['channel' => 'pos', 'customer_id' => $this->customerId,
                'ship_to_id' => $this->shipToId, 'blocked' => collect($this->cart)->filter(fn ($l) => ! empty($l['reasons']))
                    ->mapWithKeys(fn ($l) => [(string) (Product::find($l['product_id'])?->sku ?? $l['product_id']) => $l['reasons']])->all()]);
        }
        if (! $this->cart || $this->blocked) {
            $this->error = $this->cart ? 'Remove blocked items before checkout.' : 'Cart is empty.';

            return;
        }
        try {
            // #26: the sale itself lives in PosCheckout, shared with offline-sale sync.
            $so = app(\App\Pos\PosCheckout::class)->complete([
                'customer_id' => $this->customerId, 'warehouse_id' => $this->warehouseId,
                'fulfillment' => $this->fulfillment, 'ship_to_id' => $this->shipToId, 'notes' => $this->notes,
                'tender' => $this->tender, 'signature' => $this->sigInput(),
                'lines' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'qty' => $l['qty'], 'price' => $l['price']], $this->cart),
            ]);
            $change = $this->changeDue;
            $this->message = "Sale {$so->number} complete: \$".number_format((float) $so->total, 2).'.'.($change !== null && (float) $change >= 0 ? " Change due \${$change}." : '');
            $this->lastSaleId = $so->id;
            $this->lastTendered = $this->tender === 'cash' && is_numeric($this->tendered) ? (string) $this->tendered : null;
            $this->dispatch('pos-sale-complete', url: $this->receiptUrl(print: true));
            $this->cart = [];
            $this->tendered = '';
            $this->notes = '';
            $this->recallNotes = [];
            $this->resetSignature();
        } catch (ValidationException $e) {
            $this->error = implode(' ', array_unique($e->validator->errors()->all()));
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    /** #27: receipt link for the last sale (80 mm by default). */
    public function receiptUrl(string $format = '80mm', bool $print = false): ?string
    {
        if (! $this->lastSaleId) {
            return null;
        }

        return route('pos.receipt', array_filter(['so' => $this->lastSaleId, 'format' => $format === 'letter' ? 'letter' : null,
            'tendered' => $this->lastTendered, 'print' => $print ? 1 : null]));
    }

    /** @return list<string> acknowledgments for the current cart ([] = no signature needed). */
    private function signatureAcks(): array
    {
        $sig = app(SignatureService::class);
        if (! $this->cart || ! $sig->requiresSignature(Product::whereIn('id', array_column($this->cart, 'product_id'))->get())) {
            return [];
        }

        return $sig->acknowledgments((string) Customer::whereKey($this->customerId)->value('name'), $this->total, $this->tender === 'account');
    }

    public function render()
    {
        return view('livewire.counter-pos', [
            'customers' => Customer::where('active', true)->orderBy('name')->get(['id', 'name']),
            'shipTos' => ShipTo::where('customer_id', $this->customerId)->orderByDesc('is_default')->orderBy('name')->get(),
            'addShipToUrl' => $this->customerId ? \App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $this->customerId]) : null,
            'warehouses' => Warehouse::where('active', true)->get(),
            'operator' => auth()->user()?->name,
            'pinSession' => (bool) session(PosPin::SESSION_USER),
            'sigAcks' => $this->signatureAcks(),
            'canDeleteHeld' => (bool) auth()->user()?->isAdmin(),
        ]);
    }
}
