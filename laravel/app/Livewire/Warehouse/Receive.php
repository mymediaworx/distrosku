<?php

namespace App\Livewire\Warehouse;

use App\Models\PurchaseOrder;
use App\Models\ReceivingSession;
use App\Warehouse\WarehouseService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.warehouse')]
class Receive extends Component
{
    use Concerns;

    public PurchaseOrder $po;

    public ?int $sessionId = null;

    public string $lot = '';

    public string $expires = '';

    public function mount(PurchaseOrder $po): void
    {
        $this->authorizeScan();
        $this->po = $po;
        $open = ReceivingSession::where('purchase_order_id', $po->id)->where('status', 'open')->first();
        $this->sessionId = $open?->id ?? (in_array($po->status, ['draft', 'ordered', 'partial'], true) ? app(WarehouseService::class)->startReceiving($po, auth()->user())->id : null);
    }

    private function session(): ?ReceivingSession
    {
        return $this->sessionId ? ReceivingSession::find($this->sessionId) : null;
    }

    public function add(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            $r = app(WarehouseService::class)->scanReceive($this->session(), $this->scan, $this->qty, $this->lot ?: null, $this->expires ?: null);

            return "+{$r['qty']} {$r['product']->sku}".($r['product']->track_lots ? " lot {$this->lot}" : '')." · {$r['remaining']} left"
                .($r['over'] ? " · OVER-RECEIPT {$r['over']} (within allowance, logged)" : '');
        });
    }

    public function undo(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            app(WarehouseService::class)->undoLastScan($this->session());

            return 'Last scan removed';
        });
    }

    public function post(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            $s = app(WarehouseService::class)->postReceiving($this->session(), auth()->user());
            $this->po->refresh();

            return 'Received and posted. PO is '.$this->po->status.'.'.($s->variances ? ' Short: '.implode('; ', $s->variances) : '').' Put-away tasks created.';
        });
    }

    public function render()
    {
        $s = $this->session();

        return view('livewire.warehouse.receive', ['rows' => $s ? app(WarehouseService::class)->receivingStatus($s) : [], 'session' => $s]);
    }
}
