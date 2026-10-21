<?php

namespace App\Livewire;

use App\Delivery\SignatureService;
use App\Http\Middleware\PosAccess;
use App\Livewire\Concerns\SignaturePad;
use App\Models\Customer;
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

    public ?int $warehouseId = null;

    public string $scan = '';

    /** @var array<int, array{product_id:int, sku:string, name:string, qty:string, price:string, reasons:array}> */
    public array $cart = [];

    public string $tender = 'cash';

    /** Cash tendered (keypad), for change due. */
    public string $tendered = '';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(): void
    {
        $cash = Customer::where('code', 'CASH')->first() ?? Customer::first();
        $this->customerId = $cash?->id;
        $this->shipToId = $cash?->shipTos()->value('id');
        $this->warehouseId = Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id');
        // 0.14.1: an in-progress sale survives the idle lock / employee switch (kept in this terminal's session).
        if ($held = session('pos_cart')) {
            $this->cart = $held['cart'] ?? [];
            $this->customerId = $held['customerId'] ?? $this->customerId;
            $this->shipToId = $held['shipToId'] ?? $this->shipToId;
            $this->tender = $held['tender'] ?? $this->tender;
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
        session()->put('pos_cart', ['cart' => $this->cart, 'customerId' => $this->customerId, 'shipToId' => $this->shipToId, 'tender' => $this->tender]);
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

    public function updatedCustomerId(): void
    {
        $this->shipToId = ShipTo::where('customer_id', $this->customerId)->value('id');
        $this->reprice(true);
        $this->recheck();
    }

    public function updatedShipToId(): void
    {
        $this->recheck();
    }

    public function add(): void
    {
        $this->reset('message', 'error');
        $term = trim($this->scan);
        if ($term === '') {
            return;
        }
        $p = Product::where('active', true)->notArchived()->where(fn ($q) => $q->where('upc', $term)->orWhere('sku', $term))->first()
            ?? Product::where('active', true)->notArchived()->where('name', 'like', "%{$term}%")->first();
        if (! $p) {
            $this->error = "No product for \"{$term}\".";

            return;
        }
        foreach ($this->cart as $i => $line) {
            if ($line['product_id'] === $p->id) {
                $this->cart[$i]['qty'] = self::q(Money::add($line['qty'], 1));
                $this->scan = '';
                $this->reprice();
                $this->recheck();

                return;
            }
        }
        $q = $this->quote($p, '1');
        $this->cart[] = ['product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'qty' => '1', 'price' => $q->price, 'quoted' => $q->price, 'source' => $q->source, 'reasons' => []];
        $this->scan = '';
        $this->recheck();
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
        $s = ShipTo::find($this->shipToId);
        foreach ($this->cart as $i => $line) {
            $this->cart[$i]['reasons'] = ($c && $s && ($p = Product::find($line['product_id'])))
                ? app(ComplianceChecker::class)->checkLine($p, $c, $s) : ['Pick a customer and ship-to.'];
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
            $so = DB::transaction(function () use ($svc) {
                $so = SalesOrder::create([
                    'number' => SalesOrderService::nextNumber('POS', SalesOrder::class),
                    'customer_id' => $this->customerId, 'ship_to_id' => $this->shipToId, 'warehouse_id' => $this->warehouseId,
                    'channel' => 'pos', 'order_date' => now()->toDateString(),
                ]);
                foreach ($this->cart as $l) {
                    $so->lines()->create(['product_id' => $l['product_id'], 'qty' => Money::s($l['qty']), 'unit_price' => Money::s($l['price'])]);
                }
                $svc->confirm($so, $this->tender === 'account');
                // 0.15: regulated items need the counter signature (21+; account charge when tender is account).
                $sig = app(SignatureService::class);
                if ($sig->required($so->fresh())) {
                    $sig->capture($so->fresh(), $this->sigInput() ?? [], 'counter', null, $this->tender === 'account');
                }
                $svc->deliver($so);
                $svc->invoice($so);
                if ($this->tender !== 'account') {
                    $svc->recordPayment($so->fresh(), $so->fresh()->total, $this->tender, 'POS');
                }

                return $so->fresh();
            });
            $change = $this->changeDue;
            $this->message = "Sale {$so->number} complete: \${$so->total}.".($change !== null && (float) $change >= 0 ? " Change due \${$change}." : '');
            $this->cart = [];
            $this->tendered = '';
            $this->resetSignature();
        } catch (ValidationException $e) {
            $this->error = implode(' ', array_unique($e->validator->errors()->all()));
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
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
            'shipTos' => ShipTo::where('customer_id', $this->customerId)->get(),
            'warehouses' => Warehouse::where('active', true)->get(),
            'operator' => auth()->user()?->name,
            'pinSession' => (bool) session(PosPin::SESSION_USER),
            'sigAcks' => $this->signatureAcks(),
        ]);
    }
}
