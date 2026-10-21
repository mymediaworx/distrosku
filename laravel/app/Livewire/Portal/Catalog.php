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

    public string $note = '';

    public ?string $message = null;

    public ?string $error = null;

    public function add(int $productId, $qty = 1): void
    {
        $this->reset('message', 'error');
        $p = Product::find($productId);
        if (! $p || ($r = app(PortalService::class)->reasons($p, $this->contact(), $this->shipTo()))) {
            $this->error = 'Not available: '.implode(' ', $r ?? ['unknown item']);

            return;
        }
        $this->cart[$productId] = (string) ((float) ($this->cart[$productId] ?? 0) + max(1, (float) $qty));
        $this->saveCart();
        $this->message = "Added {$p->sku}.";
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
            $so = app(PortalService::class)->submit($this->contact(), $this->shipTo(), $this->cart, $this->note);
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
        $s = $this->shipTo();
        $svc = app(PortalService::class);
        $pricing = app(PortalPricing::class);
        $lines = [];
        $total = '0';
        foreach ($this->cart as $pid => $qty) {
            if ($p = Product::find($pid)) {
                $price = $pricing->price($p, $c->customer);
                $lt = Money::mul($qty, $price);
                $total = Money::add($total, $lt);
                $lines[] = ['p' => $p, 'qty' => $qty, 'price' => $price, 'total' => $lt, 'reasons' => $s ? $svc->reasons($p, $c, $s) : ['No ship-to address on file.']];
            }
        }

        return view('livewire.portal.catalog', [
            'contact' => $c, 'shipTos' => $c->allowedShipTos(), 'shipTo' => $s,
            'rows' => $s ? $svc->catalog($c, $s, $this->q, $this->departmentId) : collect(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'lines' => $lines, 'total' => $total, 'blocked' => collect($lines)->contains(fn ($l) => $l['reasons']),
        ]);
    }
}
