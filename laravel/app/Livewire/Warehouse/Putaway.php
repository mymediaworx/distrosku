<?php

namespace App\Livewire\Warehouse;

use App\Models\PutawayTask;
use App\Warehouse\WarehouseService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.warehouse')]
class Putaway extends Component
{
    use Concerns;

    public ?int $taskId = null;

    public string $bin = '';

    public function mount(): void
    {
        $this->authorizeScan();
    }

    public function choose(int $id): void
    {
        $this->taskId = $id;
        $t = PutawayTask::with('suggestedBin')->find($id);
        $this->bin = $t?->suggestedBin?->code ?? '';
        $this->ok = $this->err = null;
    }

    public function add(): void
    {
        $this->authorizeScan();
        $this->attempt(function () {
            $t = PutawayTask::findOrFail($this->taskId);
            app(WarehouseService::class)->putaway($t, $this->bin, $this->scan, auth()->user());
            $this->taskId = null;
            $this->bin = '';

            return "Put away {$t->product->sku} x".(float) $t->qty." in {$t->fresh()->bin->code}";
        });
    }

    public function render()
    {
        return view('livewire.warehouse.putaway', ['tasks' => PutawayTask::with('product', 'warehouse', 'suggestedBin')->where('status', 'open')->orderBy('id')->limit(100)->get()]);
    }
}
