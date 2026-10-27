<?php

namespace App\Livewire\Warehouse;

use App\Enums\SalesOrderStatus as S;
use App\Models\PickList;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\SalesOrder;
use App\Models\CycleCountTask;
use App\Warehouse\CycleCountPlanner;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.warehouse')]
class Hub extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->canModule('warehouse_scan'), 403);
    }

    public ?string $ok = null;

    public ?string $err = null;

    private function canCount(): bool
    {
        return (bool) auth()->user()?->canModule('counts', 'manage');
    }

    private function attempt(callable $fn): void
    {
        $this->ok = $this->err = null;
        abort_unless($this->canCount(), 403);
        try {
            $this->ok = $fn();
        } catch (\App\Exceptions\WorkflowException $e) {
            $this->err = $e->getMessage();
        }
    }

    public function takeTask(int $id): void
    {
        $this->attempt(function () use ($id) {
            app(CycleCountPlanner::class)->assign(CycleCountTask::findOrFail($id), auth()->user());

            return 'Task assigned to you.';
        });
    }

    public function releaseTask(int $id): void
    {
        $this->attempt(function () use ($id) {
            app(CycleCountPlanner::class)->assign(CycleCountTask::findOrFail($id), null);

            return 'Task released.';
        });
    }

    public function skipTask(int $id): void
    {
        $this->attempt(function () use ($id) {
            app(CycleCountPlanner::class)->skip(CycleCountTask::findOrFail($id), 'Skipped from the Warehouse hub');

            return 'Task skipped.';
        });
    }

    public function startTasks(int $warehouseId, ?int $taskId = null): void
    {
        $this->attempt(function () use ($warehouseId, $taskId) {
            $tasks = CycleCountTask::with('warehouse')->where('warehouse_id', $warehouseId)->where('status', 'open')
                ->when($taskId, fn ($q) => $q->where('id', $taskId), fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', auth()->id())->orWhereNull('assigned_to')))->get();
            $count = app(CycleCountPlanner::class)->startCount($tasks, auth()->user());
            $this->redirect(route('wh.count', $count));

            return "Started {$count->number}.";
        });
    }

    public function render()
    {
        $planner = app(CycleCountPlanner::class);
        $tasks = collect();
        if ($this->canCount()) {
            $planner->ensureCurrent();
            $tasks = $planner->today();
        }

        return view('livewire.warehouse.hub', [
            'tasks' => $tasks,
            'pos' => PurchaseOrder::with('vendor')->whereIn('status', ['ordered', 'partial', 'draft'])->orderByRaw('expected_at IS NULL')->orderBy('expected_at')->orderBy('order_date')->limit(50)->get(),
            'counts' => auth()->user()->canModule('counts', 'manage') ? \App\Models\InventoryCount::with('warehouse')->where('status', 'counting')->latest()->limit(20)->get() : collect(),
            'putaway' => PutawayTask::where('status', 'open')->count(),
            'toPick' => SalesOrder::with('customer')->where('status', S::Confirmed)->orderBy('order_date')->limit(50)->get(),
            'toPack' => PickList::with('salesOrder.customer')->where('status', 'picked')->latest('picked_at')->limit(50)->get(),
        ]);
    }
}
