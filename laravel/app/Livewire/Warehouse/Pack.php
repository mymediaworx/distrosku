<?php

namespace App\Livewire\Warehouse;

use App\Models\PickList;
use App\Models\SalesOrder;
use App\Warehouse\WarehouseService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.warehouse')]
class Pack extends Component
{
    use Concerns;

    public SalesOrder $so;

    public ?int $listId = null;

    public string $weight = '';

    public ?string $carrierId = null;

    public string $tracking = '';

    public function mount(SalesOrder $so): void
    {
        $this->authorizeScan();
        $this->so = $so;
        $this->listId = PickList::where('sales_order_id', $so->id)->whereIn('status', ['picked', 'packed'])->value('id');
        if (! $this->listId) {
            $this->err = "Order {$so->number} has no completed pick list yet.";
        }
    }

    private function list(): ?PickList
    {
        return $this->listId ? PickList::find($this->listId) : null;
    }

    public function add(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            $pkg = app(WarehouseService::class)->scanPack($this->list(), $this->scan, $this->qty);

            return "Packed into carton {$pkg->seq}";
        });
    }

    public function closeCarton(): void
    {
        $this->authorizeScan();
        $w = $this->weight;
        $this->attempt(function () use ($w) {
            $p = \Illuminate\Support\Facades\DB::transaction(function () use ($w) {
                $p = app(WarehouseService::class)->closePackage($this->list(), $w);
                if ($this->carrierId || trim($this->tracking) !== '') {
                    $p = app(\App\Warehouse\ShippingService::class)->assign($p, $this->carrierId ? (int) $this->carrierId : null, $this->tracking);
                }

                return $p;
            });

            return "Carton {$p->seq} closed".($p->tracking_number ? " · {$p->carrier?->name} {$p->tracking_number}" : '');
        });
        $this->weight = '';
        $this->tracking = '';
    }

    public function complete(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            app(WarehouseService::class)->completePack($this->list());

            return 'Packing complete.';
        });
    }

    public function render()
    {
        $l = $this->list();

        return view('livewire.warehouse.pack', ['list' => $l, 'lines' => $l ? app(WarehouseService::class)->orderedLines($l) : collect(),
            'packages' => $l ? $l->packages()->with('lines.pickLine.product')->orderBy('seq')->get() : collect()]);
    }
}
