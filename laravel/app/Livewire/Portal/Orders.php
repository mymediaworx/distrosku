<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\PortalContext;
use App\Models\SalesOrder;
use App\Portal\PortalService;
use App\Services\CustomerBalanceService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.portal')]
#[Title('Orders & invoices')]
class Orders extends Component
{
    use PortalContext, WithPagination;

    public ?int $openId = null;

    public array $skipped = [];

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    public function reorder(int $id)
    {
        $so = SalesOrder::where('customer_id', $this->contact()->customer_id)->find($id) ?? abort(404);
        [$this->cart, $this->skipped] = app(PortalService::class)->reorder($this->contact(), $so, $this->shipTo(), $this->cart);
        $this->saveCart();
        if ($this->skipped) {
            return null;
        }
        session()->flash('portal_ok', "Items from {$so->number} added to your cart.");

        return redirect()->route('portal.catalog', ['cart' => 1]);
    }

    public function render()
    {
        $c = $this->contact();
        $bal = app(CustomerBalanceService::class);

        return view('livewire.portal.orders', [
            'orders' => SalesOrder::where('customer_id', $c->customer_id)->with('lines.product', 'shipTo')
                ->when($c->ship_to_id, fn ($q) => $q->where('ship_to_id', $c->ship_to_id))->latest('id')->paginate(15),
            'aging' => $bal->aging($c->customer), 'opening' => $bal->openingRemaining($c->customer), 'customer' => $c->customer,
        ]);
    }
}
