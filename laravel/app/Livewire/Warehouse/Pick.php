<?php

namespace App\Livewire\Warehouse;

use App\Models\PickList;
use App\Models\SalesOrder;
use App\Warehouse\WarehouseService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.warehouse')]
class Pick extends Component
{
    use Concerns;

    public SalesOrder $so;

    public ?int $listId = null;

    public string $lot = '';

    public ?int $shortLineId = null;

    public string $shortQty = '';

    public string $shortReason = '';

    public string $approverEmail = '';

    public string $approverPassword = '';

    public function mount(SalesOrder $so): void
    {
        $this->authorizeScan();
        $this->so = $so;
        try {
            $this->listId = app(WarehouseService::class)->pickList($so)->id;
        } catch (\Throwable $e) {
            $this->err = $e->getMessage();
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
            $l = app(WarehouseService::class)->scanPick($this->list(), $this->scan, $this->qty, $this->lot ?: null);

            return "Picked {$l->product->sku} ".(float) $l->qty_picked.'/'.(float) $l->qty;
        });
    }

    public function startShort(int $lineId): void
    {
        $this->shortLineId = $lineId;
        $l = \App\Models\PickListLine::find($lineId);
        $this->shortQty = $l ? (string) (float) $l->qty_picked : '';
        $this->shortReason = '';
        $this->approverPassword = '';
    }

    /** Short pick: reduce the order line; a manager/admin approves (themselves, or by entering their login at the scanner). */
    public function shortPick(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            $line = \App\Models\PickListLine::where('pick_list_id', $this->listId)->findOrFail($this->shortLineId);
            $me = auth()->user();
            $approver = $me->isAdmin() ? $me : null;
            if (! $approver) {
                $u = \App\Models\User::where('email', trim($this->approverEmail))->first();
                if (! $u || ! \Illuminate\Support\Facades\Hash::check($this->approverPassword, $u->password)) {
                    throw new \App\Exceptions\WorkflowException('Manager approval failed: wrong email or password.');
                }
                $approver = $u;
            }
            $sku = $line->product->sku;
            app(WarehouseService::class)->shortPick($line, $me, $approver, $this->shortReason, $this->shortQty === '' ? null : $this->shortQty);
            $this->shortLineId = null;
            $this->approverPassword = '';

            return "Short pick on {$sku}: order reduced to ".($this->shortQty === '' ? 'picked qty' : $this->shortQty)." (approved by {$approver->name}, logged).";
        });
    }

    public function complete(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            app(WarehouseService::class)->completePick($this->list(), auth()->user());

            return 'Pick complete. Order is picked; go to Pack.';
        });
    }

    public function render()
    {
        $l = $this->list();

        return view('livewire.warehouse.pick', ['list' => $l, 'lines' => $l ? app(WarehouseService::class)->orderedLines($l) : collect()]);
    }
}
