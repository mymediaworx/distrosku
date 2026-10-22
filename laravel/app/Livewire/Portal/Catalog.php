<?php

namespace App\Livewire\Portal;

use App\Exceptions\WorkflowException;
use App\Livewire\Portal\Concerns\PortalContext;
use App\Models\Department;
use App\Models\Product;
use App\Portal\PortalPricing;
use App\Portal\PortalService;
use App\Services\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.portal')]
#[Title('Catalog')]
class Catalog extends Component
{
    use PortalContext;

    #[Url]
    public string $q = '';

    #[Url(as: 'dept')]
    public ?int $departmentId = null;

    #[Url(as: 'cart')]
    public bool $showCart = false;

    /** 0.20.3: 'shop' (sections + catalog by category) or 'again' (Buy again). */
    #[Url(as: 'view')]
    public string $view = 'shop';

    /** Buy again qty inputs: product_id => qty. */
    public array $againQty = [];

    public string $note = '';

    public ?string $message = null;

    public ?string $error = null;

    public function add(int $productId, $qty = 1): void
    {
        $this->reset('message', 'error');
        $p = Product::find($productId);
        if (! $this->location()) {
            $this->error = 'Choose a ship-to for delivery, or switch to Counter pickup.';

            return;
        }
        if (! $p || ($r = app(PortalService::class)->reasons($p, $this->contact(), $this->location() ?? $this->shipTo()))) {
            $this->error = 'Not available: '.implode(' ', $r ?? ['unknown item']);

            return;
        }
        $this->cart[$productId] = (string) ((float) ($this->cart[$productId] ?? 0) + max(1, (float) $qty));
        $this->saveCart();
        $this->message = "Added {$p->sku}.";
    }

    public function addAgain(int $productId): void
    {
        $this->add($productId, max(1, (int) ($this->againQty[$productId] ?? 1)));
    }

    /** 0.20.3: add every still-available line of a past order to the cart. */
    public function reorderOrder(int $id): void
    {
        $this->reset('message', 'error');
        $so = \App\Models\SalesOrder::where('customer_id', $this->contact()->customer_id)->find($id) ?? abort(404);
        if (! $this->location()) {
            $this->error = 'Choose a ship-to for delivery, or switch to Counter pickup.';

            return;
        }
        [$this->cart, $skipped] = app(PortalService::class)->reorder($this->contact(), $so, $this->location(), $this->cart);
        $this->saveCart();
        $this->message = "Items from {$so->number} added to your cart.".($skipped ? ' Skipped: '.implode('; ', $skipped) : '');
    }

    public function setQty(int $productId, $qty): void
    {
        $this->cart[$productId] = (string) max(0, (int) $qty);
        $this->saveCart();
    }

    public function remove(int $productId): void
    {
        unset($this->cart[$productId]);
        $this->saveCart();
    }

    public function updatedCart(): void
    {
        $this->saveCart();
    }

    public function submit()
    {
        $this->reset('message', 'error');
        $this->validate(['note' => 'nullable|string|max:500']);
        try {
            $so = app(PortalService::class)->submit($this->contact(), $this->isDelivery() ? $this->shipTo() : null, $this->cart, $this->note, $this->fulfillment);
        } catch (WorkflowException $e) {
            $this->error = $e->getMessage();

            return null;
        }
        $this->cart = [];
        $this->note = '';
        $this->saveCart();
        session()->flash('portal_ok', "Order {$so->number} submitted (".($so->status->value === 'confirmed' ? 'confirmed' : 'pending review').').');

        return redirect()->route('portal.orders');
    }

    public function render()
    {
        $c = $this->contact();
        $s = $this->location();
        $svc = app(PortalService::class);
        $pricing = app(PortalPricing::class);
        $lines = [];
        $total = '0';
        foreach ($this->cart as $pid => $qty) {
            if ($p = Product::find($pid)) {
                $price = $pricing->price($p, $c->customer);
                $lt = Money::mul($qty, $price);
                $total = Money::add($total, $lt);
                $lines[] = ['p' => $p, 'qty' => $qty, 'price' => $price, 'total' => $lt, 'reasons' => $s ? $svc->reasons($p, $c, $s) : ['No ship-to address on file for delivery. Switch to Counter pickup or contact us.']];
            }
        }

        $again = $this->view === 'again' && $s ? $svc->buyAgain($c, $s) : collect();
        foreach ($again as $a) {
            $this->againQty[$a['product']->id] ??= (string) max(1, (int) $a['last_qty']);
        }
        $rows = $s && $this->view !== 'again' ? $svc->catalog($c, $s, $this->q, $this->departmentId) : collect();

        return view('livewire.portal.catalog', [
            'sections' => $s && $this->view !== 'again' && trim($this->q) === '' && ! $this->departmentId ? $svc->sections($c, $s) : [],
            'groups' => $rows->groupBy(fn ($r) => $r['product']->department?->name ?: ($r['product']->category ?: 'Other'))->sortKeys()
                ->sortBy(fn ($g, $k) => $k === 'Other' ? 1 : 0),
            'again' => $again, 'pastOrders' => $this->view === 'again' ? \App\Models\SalesOrder::where('customer_id', $c->customer_id)
                ->whereIn('status', [\App\Enums\SalesOrderStatus::Delivered, \App\Enums\SalesOrderStatus::Invoiced])
                ->when($c->ship_to_id, fn ($q) => $q->where('ship_to_id', $c->ship_to_id))->latest('order_date')->latest('id')->limit(10)->get() : collect(),
            'searching' => trim($this->q) !== '' || $this->departmentId,
            'contact' => $c, 'shipTos' => $c->allowedShipTos(), 'shipTo' => $this->shipTo(), 'location' => $s, 'delivery' => $this->isDelivery(),
            'rows' => $rows,
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'lines' => $lines, 'total' => $total, 'blocked' => collect($lines)->contains(fn ($l) => $l['reasons']),
        ]);
    }
}
