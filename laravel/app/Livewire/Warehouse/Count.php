<?php

namespace App\Livewire\Warehouse;

use App\Inventory\CountService;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Scanner count screen: scan (case/carton barcodes count their factor), lot entry, manual totals, undo, submit. */
#[Layout('layouts.warehouse')]
class Count extends Component
{
    use Concerns;

    public InventoryCount $count;

    public string $lot = '';

    public string $expires = '';

    public bool $zero = false;

    public function mount(InventoryCount $count): void
    {
        abort_unless(auth()->user()?->canModule('counts', 'manage'), 403);
        $this->count = $count;
    }

    protected function authorizeScan(): void
    {
        abort_unless(auth()->user()?->canModule('counts', 'manage'), 403);
    }

    public function add(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            $l = app(CountService::class)->scan($this->count, $this->scan, $this->qty, $this->lot ?: null, $this->expires ?: null, auth()->user());

            return "Counted {$l->product->sku}".($l->lot ? " lot {$l->lot->lot_number}" : '').' · total '.(float) $l->counted;
        });
    }

    public function setLine(int $id, $qty): void
    {
        $this->authorizeScan();
        $this->attempt(function () use ($id, $qty) {
            $l = InventoryCountLine::where('inventory_count_id', $this->count->id)->findOrFail($id);
            app(CountService::class)->setCounted($l, $qty, auth()->user());

            return "{$l->product->sku} set to ".(float) $qty;
        });
    }

    public function undo(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            app(CountService::class)->undoLastScan($this->count);

            return 'Last scan removed';
        });
    }

    public function submit(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            app(CountService::class)->submit($this->count, $this->zero, auth()->user());
            $this->count->refresh();

            return 'Submitted. A manager approves and posts the variances.';
        });
    }

    public function render()
    {
        $this->count->refresh();

        return view('livewire.warehouse.count', [
            'lines' => $this->count->lines()->with('product', 'bin', 'lot')->get()->sortBy(fn ($l) => [$l->bin?->code ?? 'zzz', $l->product->sku, $l->lot?->lot_number ?? ''])->values(),
            'showExpected' => CountService::showExpected($this->count, auth()->user()),
        ]);
    }
}
