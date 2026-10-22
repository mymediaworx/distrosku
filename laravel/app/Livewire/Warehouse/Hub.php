<?php

namespace App\Livewire\Warehouse;

use App\Enums\SalesOrderStatus as S;
use App\Models\PickList;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\SalesOrder;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.warehouse')]
class Hub extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->canModule('warehouse_scan'), 403);
    }

    public function render()
    {
        return view('livewire.warehouse.hub', [
            'pos' => PurchaseOrder::with('vendor')->whereIn('status', ['ordered', 'partial', 'draft'])->orderByRaw('expected_at IS NULL')->orderBy('expected_at')->orderBy('order_date')->limit(50)->get(),
            'counts' => auth()->user()->canModule('counts', 'manage') ? \App\Models\InventoryCount::with('warehouse')->where('status', 'counting')->latest()->limit(20)->get() : collect(),
            'putaway' => PutawayTask::where('status', 'open')->count(),
            'toPick' => SalesOrder::with('customer')->where('status', S::Confirmed)->orderBy('order_date')->limit(50)->get(),
            'toPack' => PickList::with('salesOrder.customer')->where('status', 'picked')->latest('picked_at')->limit(50)->get(),
        ]);
    }
}
