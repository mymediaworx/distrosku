<?php

namespace App\Inventory;

use App\Audit\Audit;
use App\Exceptions\WorkflowException;
use App\Models\Inventory;
use App\Models\QuickCount;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\Money;
use Illuminate\Support\Facades\DB;

/** 0.20.20: Quick Tools counts. submit() only records; apply() posts the difference as an adjustment (admin). */
class QuickCountService
{
    public function __construct(private InventoryService $inventory) {}

    public function submit(int $productId, int $warehouseId, mixed $qty, string $by, ?string $note = null): QuickCount
    {
        if (! is_numeric($qty) || (float) $qty < 0) {
            throw new WorkflowException('Counted quantity must be zero or more.');
        }

        return QuickCount::create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'counted_qty' => Money::s($qty),
            'counted_by' => trim($by), 'note' => $note ? trim($note) : null]);
    }

    public function apply(QuickCount $q, User $by): QuickCount
    {
        if ($q->status !== 'pending') {
            throw new WorkflowException('This count was already reviewed.');
        }

        return DB::transaction(function () use ($q, $by) {
            $q->loadMissing('product', 'warehouse');
            $onHand = (string) (Inventory::where('product_id', $q->product_id)->where('warehouse_id', $q->warehouse_id)->value('qty') ?? '0');
            $delta = Money::of($q->counted_qty)->minus(Money::of($onHand));
            if (! $delta->isZero()) {
                $this->inventory->move($q->product, $q->warehouse, $delta, 'adjustment', $q, null, "Quick count by {$q->counted_by}");
            }
            $q->update(['status' => 'applied', 'on_hand_at_review' => Money::s($onHand), 'applied_delta' => Money::s($delta), 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            Audit::action('quick_count_applied', $q, "Quick count applied: {$q->product->sku} {$q->warehouse->code} {$onHand} -> {$q->counted_qty}");

            return $q;
        });
    }

    public function dismiss(QuickCount $q, User $by): QuickCount
    {
        if ($q->status !== 'pending') {
            throw new WorkflowException('This count was already reviewed.');
        }
        $q->update(['status' => 'dismissed', 'reviewed_by' => $by->id, 'reviewed_at' => now()]);

        return $q;
    }
}
