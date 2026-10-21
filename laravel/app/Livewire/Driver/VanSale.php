<?php

namespace App\Livewire\Driver;

use App\Delivery\SignatureService;
use App\Delivery\VanSaleService;
use App\Livewire\Concerns\SignaturePad;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ShipTo;
use App\Models\Warehouse;
use App\Pricing\PricingEngine;
use App\Services\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.driver')]
#[Title('Van sale')]
class VanSale extends Component
{
    use SignaturePad;

    public ?int $vanId = null;

    public ?int $customerId = null;

    public ?int $shipToId = null;

    public string $scan = '';

    /** @var list<array{product_id:int, name:string, sku:string, qty:string, price:string}> */
    public array $cart = [];

    public string $tender = 'cash';

    public ?string $ok = null;

    public ?string $err = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canModule('van_sales', 'manage'), 403);
        $this->vanId = $this->vans()->first()?->id;
    }

    private function vans()
    {
        $u = auth()->user();

        return Warehouse::where('is_van', true)->where('active', true)
            ->when(! $u->isAdmin(), fn ($q) => $q->where(fn ($w) => $w->where('van_driver_id', $u->id)->orWhereNull('van_driver_id')))->orderBy('name');
    }

    public function updatedCustomerId(): void
    {
        $this->shipToId = ShipTo::where('customer_id', $this->customerId)->value('id');
        $this->reprice();
        $this->resetSignature();
    }

    public function add(): void
    {
        $this->ok = $this->err = null;
        $t = trim($this->scan);
        if ($t === '') {
            return;
        }
        $p = Product::where('active', true)->notArchived()->where(fn ($q) => $q->where('upc', $t)->orWhere('sku', $t))->first()
            ?? Product::where('active', true)->notArchived()->where('name', 'like', "%{$t}%")->first();
        if (! $p) {
            $this->err = "No product for \"{$t}\".";

            return;
        }
        foreach ($this->cart as $i => $l) {
            if ($l['product_id'] === $p->id) {
                $this->cart[$i]['qty'] = Money::s(Money::add($l['qty'], 1));
                $this->scan = '';
                $this->reprice();

                return;
            }
        }
        $this->cart[] = ['product_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'qty' => '1', 'price' => '0'];
        $this->scan = '';
        $this->reprice();
    }

    public function remove(int $i): void
    {
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);
        $this->reprice();
    }

    public function updatedCart(): void
    {
        $this->reprice();
    }

    /** Van prices always come from the pricing engine (no driver overrides). */
    private function reprice(): void
    {
        $c = Customer::find($this->customerId);
        foreach ($this->cart as $i => $l) {
            if ($p = Product::find($l['product_id'])) {
                $this->cart[$i]['price'] = app(PricingEngine::class)->quote($p, $c, $l['qty'] ?: 1)->price;
            }
        }
    }

    public function getTotalProperty(): string
    {
        return Money::s(Money::add(0, ...array_map(fn ($l) => Money::mul($l['qty'] ?: 0, $l['price']), $this->cart)));
    }

    public function sell(VanSaleService $svc): void
    {
        abort_unless(auth()->user()?->canModule('van_sales', 'manage'), 403);
        $this->ok = $this->err = null;
        try {
            $van = $this->vans()->whereKey($this->vanId)->firstOrFail();
            $c = Customer::findOrFail($this->customerId);
            $st = ShipTo::where('customer_id', $c->id)->findOrFail($this->shipToId);
            $so = $svc->sell($van, $c, $st, array_map(fn ($l) => ['product_id' => $l['product_id'], 'qty' => $l['qty']], $this->cart), $this->tender, $this->sigInput());
            $this->ok = "Sale {$so->number} invoiced: \$".number_format((float) $so->total, 2).($this->tender === 'account' ? ' charged to account.' : " paid by {$this->tender}.");
            $this->cart = [];
            $this->resetSignature();
        } catch (ValidationException $e) {
            $this->err = implode(' ', array_unique($e->validator->errors()->all()));
        } catch (Throwable $e) {
            $this->err = $e->getMessage();
        }
    }

    public function render(SignatureService $sig)
    {
        $c = Customer::find($this->customerId);
        $needs = $sig->requiresSignature(Product::whereIn('id', array_column($this->cart, 'product_id'))->get());
        $stock = $this->vanId ? Inventory::where('warehouse_id', $this->vanId)->whereIn('product_id', array_column($this->cart, 'product_id'))->pluck('qty', 'product_id') : collect();

        return view('livewire.driver.van-sale', [
            'vans' => $this->vans()->get(), 'customers' => Customer::where('active', true)->orderBy('name')->get(['id', 'name', 'terms']),
            'shipTos' => ShipTo::where('customer_id', $this->customerId)->get(), 'stock' => $stock, 'needsSig' => $needs && $c,
            'acks' => $needs && $c ? $sig->acknowledgments($c->name, $this->total, $this->tender === 'account') : [],
        ]);
    }
}
