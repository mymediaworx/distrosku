<?php

namespace App\Warehouse;

use App\Exceptions\WorkflowException;
use App\Models\Lot;
use App\Models\LotContact;
use App\Models\LotMovement;
use App\Models\LotReviewItem;
use App\Models\LotStock;
use App\Models\PickLineLot;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\ProductCoa;
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

    public function receive(Product $p, int $warehouseId, string $lotNumber, ?string $expires, mixed $qty, ?PurchaseOrder $po = null, ?int $coaId = null): Lot
    {
        $lotNumber = trim($lotNumber);
        if ($lotNumber === '') {
            throw new WorkflowException("{$p->sku} is lot-tracked: enter the lot number.");
        }

        return DB::transaction(function () use ($p, $warehouseId, $lotNumber, $expires, $qty, $po, $coaId) {
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
            if ($coaId && ($coa = ProductCoa::find($coaId))) {
                $this->attachCoa($lot, $coa); // 0.20.15: COA chosen at receiving
            }

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

    /**
     * 0.20.15 (#36) API for the POS / van lot picker: sellable lots of a product in a warehouse, FEFO order.
     * @return list<array{lot_id: int, lot_number: string, expires_on: ?string, qty: string, coa: bool}>
     */
    public function lotsOnHand(Product $p, int $warehouseId): array
    {
        return $this->available($p, $warehouseId)->map(fn ($a) => ['lot_id' => $a['lot']->id, 'lot_number' => $a['lot']->lot_number,
            'expires_on' => $a['lot']->expires_on?->toDateString(), 'qty' => Money::s($a['qty']), 'coa' => ! $a['lot']->coaMissing()])->values()->all();
    }

    /** Throws when a lot chosen on a sale line can't be sold (wrong product, on hold, expired). Null is fine. */
    public function assertSellable(?int $lotId, Product $p): void
    {
        if (! $lotId) {
            return;
        }
        $lot = Lot::find($lotId);
        if (! $lot || (int) $lot->product_id !== (int) $p->id) {
            throw new WorkflowException("Lot #{$lotId} is not a lot of {$p->sku}.");
        }
        if ($lot->on_hold) {
            throw new WorkflowException("Lot {$lot->lot_number} of {$p->sku} is on hold and can't be sold.");
        }
        if ($lot->expires_on && $lot->expires_on->lt(now()->startOfDay())) {
            throw new WorkflowException("Lot {$lot->lot_number} of {$p->sku} expired on {$lot->expires_on->toDateString()}.");
        }
    }

    /**
     * At delivery: take lot stock and record who received each lot. Order of preference per line:
     * 1. the lot chosen on the line (lot_id); 2. lots picked on the pick list; 3. counter/van (pos, van): the only lot
     * on hand; other orders: FEFO. Whatever can't be assigned goes to the Needs review list (never silent, never blocks).
     * Lot stock is a side record: inventory quantity and GL are not touched here.
     * @return array<string, string> sku => unassigned qty (also written to lot_review_items)
     */
    public function consumeForOrder(SalesOrder $so): array
    {
        $unlotted = [];
        $so->loadMissing('lines.product');
        $counter = in_array($so->channel, ['pos', 'van'], true);
        $pick = \App\Models\PickList::where('sales_order_id', $so->id)->whereIn('status', ['picked', 'packed'])->first();
        foreach ($so->lines as $l) {
            if (! $l->product->track_lots) {
                continue;
            }
            $need = Money::of($l->qty);
            $plan = [];
            $reason = null;
            $avail = null;
            if ($l->lot_id) {
                $lot = Lot::find($l->lot_id);
                $ok = $lot && (int) $lot->product_id === (int) $l->product_id && ! $lot->on_hold;
                $have = $ok ? Money::of(LotStock::where('lot_id', $lot->id)->where('warehouse_id', $so->warehouse_id)->value('qty') ?? 0) : BigDecimal::zero();
                if ($have->isPositive()) {
                    $plan[] = [$lot, BigDecimal::min($have, $need)];
                    $need = $need->minus(end($plan)[1]);
                }
                $reason = $ok ? 'lot_short' : 'lot_unavailable';
            } elseif ($pick && ($pl = $pick->lines()->where('sales_order_line_id', $l->id)->first())) {
                foreach ($pl->lots()->with('lot')->where('qty_picked', '>', 0)->get() as $a) {
                    $plan[] = [$a->lot, BigDecimal::min(Money::of($a->qty_picked), $need)];
                    $need = $need->minus(end($plan)[1]);
                }
                $reason = 'lot_short';
            }
            if (! $l->lot_id && ! $plan && $need->isPositive()) {
                $avail = $this->available($l->product, $so->warehouse_id);
                if ($avail->isEmpty()) {
                    $reason = 'no_lot_stock';
                } elseif ($counter && $avail->count() > 1) {
                    $reason = 'several_lots';
                } else {
                    foreach ($counter ? $avail->take(1) : $avail as $av) {
                        if (! $need->isPositive()) {
                            break;
                        }
                        $take = BigDecimal::min($need, $av['qty']);
                        $plan[] = [$av['lot'], $take];
                        $need = $need->minus($take);
                    }
                    $reason = 'lot_short';
                }
            }
            $first = null;
            foreach ($plan as [$lot, $q]) {
                if (! $q->isPositive()) {
                    continue;
                }
                $first ??= $lot;
                $this->saleMovement($so, $lot, $q);
            }
            if (! $l->lot_id && $first && count($plan) === 1) {
                $l->update(['lot_id' => $first->id]); // record the auto-assigned lot on the line
            }
            if ($need->isPositive()) {
                $unlotted[$l->product->sku] = Money::s($need);
                LotReviewItem::create(['sales_order_id' => $so->id, 'sales_order_line_id' => $l->id, 'product_id' => $l->product_id,
                    'warehouse_id' => $so->warehouse_id, 'qty' => Money::s($need), 'reason' => $reason ?? 'no_lot_stock']);
            }
        }

        return $unlotted;
    }

    private function saleMovement(SalesOrder $so, Lot $lot, BigDecimal $q): void
    {
        $this->bump($lot, $so->warehouse_id, $q->negated());
        LotMovement::create(['lot_id' => $lot->id, 'warehouse_id' => $so->warehouse_id, 'qty' => Money::s($q->negated()), 'type' => 'sale',
            'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'ship_to_id' => $so->ship_to_id, 'user_id' => auth()->id()]);
    }

    /**
     * Resolve a Needs review item. With a lot: that lot's stock is taken (up to what it holds) and the sale is traced to it.
     * Without a lot (e.g. legacy unlotted stock): closed with a required note. Inventory qty and GL are not touched.
     */
    public function resolveReview(LotReviewItem $item, ?Lot $lot, int $userId, ?string $note = null): LotReviewItem
    {
        if ($item->status !== 'open') {
            throw new WorkflowException('This item is already resolved.');
        }
        if (! $lot && trim((string) $note) === '') {
            throw new WorkflowException('Choose the lot, or give a note (e.g. "legacy stock received before lot tracking").');
        }

        return DB::transaction(function () use ($item, $lot, $userId, $note) {
            if ($lot) {
                if ((int) $lot->product_id !== (int) $item->product_id) {
                    throw new WorkflowException("Lot {$lot->lot_number} is not a lot of {$item->product->sku}.");
                }
                $so = $item->salesOrder;
                $have = Money::of(LotStock::where('lot_id', $lot->id)->where('warehouse_id', $item->warehouse_id)->value('qty') ?? 0);
                $q = BigDecimal::min($have, Money::of($item->qty));
                if ($q->isPositive()) {
                    $this->bump($lot, $item->warehouse_id, $q->negated());
                }
                LotMovement::create(['lot_id' => $lot->id, 'warehouse_id' => $item->warehouse_id, 'qty' => Money::s($q->negated()), 'type' => 'sale',
                    'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'ship_to_id' => $so->ship_to_id, 'user_id' => $userId,
                    'note' => mb_substr('Needs review resolved'.($q->isLessThan(Money::of($item->qty)) ? ' (lot stock covered '.Money::s($q).' of '.Money::s($item->qty).')' : ''), 0, 250)]);
                if ($item->line && ! $item->line->lot_id) {
                    $item->line->update(['lot_id' => $lot->id]);
                }
            }
            $item->update(['status' => 'resolved', 'lot_id' => $lot?->id, 'resolved_by' => $userId, 'resolved_at' => now(), 'note' => trim((string) $note) ?: null]);

            return $item;
        });
    }

    /** Link a COA to a lot (keeps batch_lot text in step). */
    public function attachCoa(Lot $lot, ProductCoa $coa): void
    {
        if ((int) $coa->product_id !== (int) $lot->product_id) {
            throw new WorkflowException('That COA belongs to another product.');
        }
        $coa->update(['lot_id' => $lot->id, 'batch_lot' => $coa->batch_lot ?: $lot->lot_number]);
    }

    /**
     * Trace search by lot number, SKU or order number. Returns the matching lots with receipts, sales (customer/ship-to),
     * on-hand, COA state and contact log. Hold / CSV / contacted act on the lots returned.
     * @return array{query: string, lots: list<array>}
     */
    public function trace(string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return ['query' => '', 'lots' => []];
        }
        $ids = Lot::where('lot_number', $q)->pluck('id');
        if ($ids->isEmpty()) {
            $pid = Product::where('sku', $q)->orWhere('upc', $q)->pluck('id');
            $ids = $pid->isNotEmpty() ? Lot::whereIn('product_id', $pid)->pluck('id') : collect();
        }
        if ($ids->isEmpty()) {
            $so = SalesOrder::where('number', $q)->first();
            $po = $so ? null : PurchaseOrder::where('number', $q)->first();
            $ids = LotMovement::when($so, fn ($w) => $w->where('sales_order_id', $so->id))->when($po, fn ($w) => $w->where('purchase_order_id', $po->id))
                ->when(! $so && ! $po, fn ($w) => $w->whereRaw('1 = 0'))->distinct()->pluck('lot_id');
        }
        $lots = Lot::with('product')->whereIn('id', $ids)->orderBy('expires_on')->limit(200)->get();

        return ['query' => $q, 'lots' => $lots->map(function (Lot $lot) {
            $mv = LotMovement::with('salesOrder', 'purchaseOrder', 'customer', 'shipTo')->where('lot_id', $lot->id)->orderBy('created_at')->get();

            return [
                'lot' => $lot,
                'received' => $mv->where('type', 'receipt')->map(fn ($m) => ['date' => $m->created_at->toDateString(), 'po' => $m->purchaseOrder?->number, 'qty' => Money::s($m->qty)])->values()->all(),
                'sales' => $mv->where('type', 'sale')->map(fn ($m) => ['date' => $m->created_at->toDateString(), 'order' => $m->salesOrder?->number,
                    'customer_id' => $m->customer_id, 'ship_to_id' => $m->ship_to_id,
                    'customer' => trim(($m->customer?->code ?? '').' '.($m->customer?->name ?? '')), 'ship_to' => trim(($m->shipTo?->name ?? '').' '.($m->shipTo?->city ?? '').' '.($m->shipTo?->state ?? '')),
                    'phone' => $m->customer?->phone ?? $m->shipTo?->phone ?? '', 'email' => $m->customer?->email ?? '', 'qty' => Money::s(Money::of($m->qty)->negated())])->values()->all(),
                'on_hand' => Money::s(Money::add(...($lot->stocks()->pluck('qty')->all() ?: [0]))),
                'coa_missing' => $lot->coaMissing(),
                'coas' => ProductCoa::where('lot_id', $lot->id)->get(),
                'contacts' => LotContact::with('customer', 'user')->where('lot_id', $lot->id)->orderByDesc('contacted_at')->get(),
            ];
        })->all()];
    }

    /** Flat rows for the Trace CSV (one row per shipment, plus a row per lot still on hand with no shipments). */
    public function traceCsvRows(string $query): array
    {
        $rows = [['SKU', 'Item', 'Lot', 'Expires', 'On hold', 'COA', 'Shipped', 'Order', 'Customer', 'Ship to', 'Phone', 'Email', 'Qty', 'Contacted']];
        foreach ($this->trace($query)['lots'] as $t) {
            $l = $t['lot'];
            $base = [$l->product->sku, $l->product->name, $l->lot_number, $l->expires_on?->toDateString() ?? '', $l->on_hold ? 'yes' : 'no', $t['coa_missing'] ? 'MISSING' : 'ok'];
            foreach ($t['sales'] as $s) {
                $c = $t['contacts']->first(fn ($x) => (int) $x->customer_id === (int) $s['customer_id']);
                $rows[] = array_merge($base, [$s['date'], $s['order'] ?? '', $s['customer'], $s['ship_to'], $s['phone'], $s['email'], $s['qty'],
                    $c ? $c->contacted_at->toDateString().' '.$c->method.' by '.($c->user?->name ?? '') : '']);
            }
            if (! $t['sales']) {
                $rows[] = array_merge($base, ['', '', '', '', '', '', 'on hand '.$t['on_hand'], '']);
            }
        }

        return $rows;
    }

    public function logContact(Lot $lot, ?int $customerId, ?int $shipToId, string $method, ?string $note, int $userId, ?string $at = null): LotContact
    {
        if (! array_key_exists($method, LotContact::METHODS)) {
            throw new WorkflowException('Choose how the customer was contacted.');
        }

        return LotContact::create(['lot_id' => $lot->id, 'customer_id' => $customerId, 'ship_to_id' => $shipToId, 'user_id' => $userId,
            'contacted_at' => $at ? CarbonImmutable::parse($at) : now(), 'method' => $method, 'note' => trim((string) $note) ?: null]);
    }

    public function setHold(Lot $lot, bool $hold, ?int $userId = null): void
    {
        $lot->update(['on_hold' => $hold]);
        LotMovement::create(['lot_id' => $lot->id, 'warehouse_id' => $lot->stocks()->value('warehouse_id') ?? \App\Models\Warehouse::value('id'), 'qty' => 0, 'type' => 'adjust',
            'user_id' => $userId ?? auth()->id(), 'note' => $hold ? 'Placed on hold' : 'Released from hold']);
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
