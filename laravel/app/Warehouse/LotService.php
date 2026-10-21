<?php

namespace App\Warehouse;

use App\Exceptions\WorkflowException;
use App\Models\Lot;
use App\Models\LotMovement;
use App\Models\LotStock;
use App\Models\PickLineLot;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Services\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lot + expiry for products with track_lots. Lot stock sits beside the normal inventory row (which stays the source of truth
 * for quantity and GL). FEFO = first-expiring-first-out; expired and on-hold lots are never allocated.
 * Stock received before lot tracking is "unlotted": allowed to ship, with no lot recorded.
 */
class LotService
{
    public static function alertDays(): int
    {
        return (int) \App\Models\Setting::get('lot_expiry_alert_days', 30);
    }

    public function receive(Product $p, int $warehouseId, string $lotNumber, ?string $expires, mixed $qty, ?PurchaseOrder $po = null): Lot
    {
        $lotNumber = trim($lotNumber);
        if ($lotNumber === '') {
            throw new WorkflowException("{$p->sku} is lot-tracked: enter the lot number.");
        }

        return DB::transaction(function () use ($p, $warehouseId, $lotNumber, $expires, $qty, $po) {
            $lot = Lot::firstOrCreate(['product_id' => $p->id, 'lot_number' => $lotNumber], ['expires_on' => $expires ?: null]);
            if ($expires && $lot->expires_on && $lot->expires_on->toDateString() !== CarbonImmutable::parse($expires)->toDateString()) {
                throw new WorkflowException("Lot {$lotNumber} already exists with expiry {$lot->expires_on->toDateString()}.");
            }
            if ($expires && ! $lot->expires_on) {
                $lot->update(['expires_on' => $expires]);
            }
            $this->bump($lot, $warehouseId, Money::of($qty));
            LotMovement::create(['lot_id' => $lot->id, 'warehouse_id' => $warehouseId, 'qty' => Money::s($qty), 'type' => 'receipt',
                'purchase_order_id' => $po?->id, 'user_id' => auth()->id()]);

            return $lot;
        });
    }

    private function bump(Lot $lot, int $warehouseId, BigDecimal $delta): void
    {
        $s = LotStock::where('lot_id', $lot->id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first()
            ?? LotStock::create(['lot_id' => $lot->id, 'warehouse_id' => $warehouseId, 'qty' => 0]);
        $new = Money::of($s->qty)->plus($delta);
        if ($new->isNegative()) {
            throw new WorkflowException("Lot {$lot->lot_number} has only {$s->qty} in stock.");
        }
        $s->update(['qty' => Money::s($new)]);
    }

    /** Count adjustment on a lot (signed), with a movement row. */
    public function adjust(Lot $lot, int $warehouseId, mixed $delta, string $note, ?int $userId = null): void
    {
        $d = Money::of($delta);
        if ($d->isZero()) {
            return;
        }
        $this->bump($lot, $warehouseId, $d);
        LotMovement::create(['lot_id' => $lot->id, 'warehouse_id' => $warehouseId, 'qty' => Money::s($d), 'type' => 'adjust', 'user_id' => $userId ?? auth()->id(), 'note' => $note]);
    }

    /** Sellable lots in FEFO order with their on-hand qty. @return Collection<int, array{lot: Lot, qty: BigDecimal}> */
    public function available(Product $p, int $warehouseId): Collection
    {
        $today = now()->toDateString();

        return LotStock::with('lot')->where('warehouse_id', $warehouseId)->where('qty', '>', 0)
            ->whereHas('lot', fn ($q) => $q->where('product_id', $p->id)->where('on_hold', false)
                ->where(fn ($w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today)))
            ->get()->sortBy(fn ($s) => [$s->lot->expires_on ? 0 : 1, $s->lot->expires_on?->toDateString() ?? '', $s->lot->id])
            ->map(fn ($s) => ['lot' => $s->lot, 'qty' => Money::of($s->qty)])->values();
    }

    /** FEFO allocation for one pick line (replaces any previous allocation). Shortfall stays unallocated (unlotted stock). */
    public function allocate(PickListLine $line, int $warehouseId): void
    {
        $p = $line->product;
        if (! $p->track_lots) {
            return;
        }
        $line->lots()->delete();
        $need = Money::of($line->qty);
        foreach ($this->available($p, $warehouseId) as $a) {
            if (! $need->isPositive()) {
                break;
            }
            $take = BigDecimal::min($need, $a['qty']);
            PickLineLot::create(['pick_list_line_id' => $line->id, 'lot_id' => $a['lot']->id, 'qty_allocated' => Money::s($take)]);
            $need = $need->minus($take);
        }
    }

    /** Record a picked lot on a pick line, enforcing FEFO (only allocated lots, earliest first). */
    public function pick(PickListLine $line, ?string $lotNumber, BigDecimal $qty): void
    {
        $allocs = $line->lots()->with('lot')->get()->sortBy(fn ($a) => [$a->lot->expires_on ? 0 : 1, $a->lot->expires_on?->toDateString() ?? '', $a->lot_id]);
        if ($allocs->isEmpty()) {
            return; // no lot stock: unlotted legacy stock
        }
        $open = $allocs->filter(fn ($a) => Money::of($a->qty_allocated)->isGreaterThan(Money::of($a->qty_picked)));
        if (! $lotNumber) {
            $first = $open->first();
            throw new WorkflowException("{$line->product->sku} is lot-tracked: enter the lot".($first ? " (FEFO: lot {$first->lot->lot_number}, exp {$first->lot->expires_on?->toDateString()})" : '').'.');
        }
        $a = $open->first(fn ($a) => strcasecmp($a->lot->lot_number, trim($lotNumber)) === 0);
        if (! $a) {
            $first = $open->first();
            throw new WorkflowException("FEFO: pick lot {$first?->lot->lot_number} (exp {$first?->lot->expires_on?->toDateString()}) before lot ".trim($lotNumber).'.');
        }
        $earliest = $open->first();
        if ($earliest->lot->expires_on && $a->lot->expires_on && $a->lot->expires_on->gt($earliest->lot->expires_on)) {
            throw new WorkflowException("FEFO: pick lot {$earliest->lot->lot_number} (exp {$earliest->lot->expires_on->toDateString()}) first.");
        }
        if ($qty->isGreaterThan(Money::of($a->qty_allocated)->minus(Money::of($a->qty_picked)))) {
            throw new WorkflowException("Lot {$a->lot->lot_number}: only ".Money::s(Money::of($a->qty_allocated)->minus(Money::of($a->qty_picked))).' allocated on this pick.');
        }
        $a->update(['qty_picked' => Money::add($a->qty_picked, $qty)]);
    }

    /** At delivery: take lot stock (picked lots first, otherwise FEFO) and record who received each lot. */
    public function consumeForOrder(SalesOrder $so): array
    {
        $unlotted = [];
        $so->loadMissing('lines.product');
        $pick = \App\Models\PickList::where('sales_order_id', $so->id)->whereIn('status', ['picked', 'packed'])->first();
        foreach ($so->lines as $l) {
            if (! $l->product->track_lots) {
                continue;
            }
            $need = Money::of($l->qty);
            $plan = [];
            if ($pick && ($pl = $pick->lines()->where('sales_order_line_id', $l->id)->first())) {
                foreach ($pl->lots()->with('lot')->where('qty_picked', '>', 0)->get() as $a) {
                    $plan[] = [$a->lot, BigDecimal::min(Money::of($a->qty_picked), $need)];
                    $need = $need->minus(end($plan)[1]);
                }
            }
            if ($need->isPositive() && ! $plan) {
                foreach ($this->available($l->product, $so->warehouse_id) as $av) {
                    if (! $need->isPositive()) {
                        break;
                    }
                    $take = BigDecimal::min($need, $av['qty']);
                    $plan[] = [$av['lot'], $take];
                    $need = $need->minus($take);
                }
            }
            foreach ($plan as [$lot, $q]) {
                if (! $q->isPositive()) {
                    continue;
                }
                $this->bump($lot, $so->warehouse_id, $q->negated());
                LotMovement::create(['lot_id' => $lot->id, 'warehouse_id' => $so->warehouse_id, 'qty' => Money::s($q->negated()), 'type' => 'sale',
                    'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'ship_to_id' => $so->ship_to_id, 'user_id' => auth()->id()]);
            }
            if ($need->isPositive()) {
                $unlotted[$l->product->sku] = Money::s($need);
            }
        }

        return $unlotted;
    }

    /** Recall look-up: every customer/ship-to that received the lot, plus what is still on hand. */
    public function recall(string $lotNumber, ?int $productId = null): array
    {
        $lots = Lot::with('product')->where('lot_number', trim($lotNumber))->when($productId, fn ($q) => $q->where('product_id', $productId))->get();
        $rows = [];
        $t = BigDecimal::zero();
        foreach ($lots as $lot) {
            foreach (LotMovement::with('salesOrder', 'customer', 'shipTo')->where('lot_id', $lot->id)->where('type', 'sale')->orderBy('created_at')->get() as $m) {
                $q = Money::of($m->qty)->negated();
                $t = $t->plus($q);
                $rows[] = [$lot->product->sku, $lot->lot_number, $lot->expires_on?->toDateString() ?? '', $m->created_at->toDateString(),
                    $m->salesOrder?->number ?? '', trim(($m->customer?->code ?? '').' '.($m->customer?->name ?? '')),
                    trim(($m->shipTo?->name ?? '').' '.($m->shipTo?->city ?? '').' '.($m->shipTo?->state ?? '')), $m->customer?->phone ?? $m->shipTo?->phone ?? '', Money::s($q)];
            }
        }
        $onHand = $lots->flatMap(fn ($l) => $l->stocks()->with('warehouse')->where('qty', '>', 0)->get()->map(fn ($s) => "{$l->product->sku} {$s->warehouse->code}: ".rtrim(rtrim((string) $s->qty, '0'), '.')))->all();

        return ['key' => 'lot_recall', 'title' => 'Recall look-up: lot '.trim($lotNumber), 'period' => 'All shipments to date',
            'columns' => ['SKU', 'Lot', 'Expires', 'Shipped', 'Order', 'Customer', 'Ship to', 'Phone', 'Qty'],
            'rows' => $rows, 'totals' => ['', '', '', '', '', count(array_unique(array_column($rows, 5))).' customer(s)', '', '', Money::s($t)],
            'notes' => [$lots->isEmpty() ? 'No lot with that number.' : 'Still on hand: '.($onHand ? implode('; ', $onHand) : 'none').'.',
                $lots->contains('on_hold', true) ? 'Lot is ON HOLD (not allocated to picks).' : 'Put the lot on hold in Inventory > Lots to stop it being picked.']];
    }
}
