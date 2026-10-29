<?php

namespace App\Warehouse;

use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\Bin;
use App\Models\Inventory;
use App\Models\Package;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\ReceivingSession;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Money;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use App\Services\Sequence;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Scan-based warehouse flow. Stock and GL still move only through the existing services:
 * receiving posts via PurchaseOrderService::receive, picking completes via SalesOrderService::pick.
 */
class WarehouseService
{
    public function __construct(private PurchaseOrderService $po, private SalesOrderService $so, private LotService $lots) {}

    private static function product(string $code)
    {
        return self::resolve($code)['product'];
    }

    /** Product + base units per scan (a case barcode counts as its factor). */
    private static function resolve(string $code): array
    {
        $r = ProductLookup::resolve($code);
        if (! $r) {
            throw new WorkflowException("Unknown barcode or SKU '$code'.");
        }

        return $r;
    }

    /** Scanned quantity in base units: entered qty x unit factor of the scanned barcode. */
    private static function baseQty(array $r, mixed $qty): BigDecimal
    {
        return self::qty($qty)->multipliedBy(Money::of($r['factor']))->toScale(4);
    }

    private static function qty(mixed $q): BigDecimal
    {
        $v = Money::of($q ?: 1);
        if ($v->isNegativeOrZero()) {
            throw new WorkflowException('Quantity must be positive.');
        }

        return $v;
    }

    // ---------------- receiving ----------------

    public function startReceiving(PurchaseOrder $po, ?User $by): ReceivingSession
    {
        if (! in_array($po->status, ['draft', 'ordered', 'partial'], true)) {
            throw new WorkflowException("PO {$po->number} is {$po->status}; nothing to receive.");
        }

        return ReceivingSession::firstOrCreate(['purchase_order_id' => $po->id, 'status' => 'open'], ['user_id' => $by?->id]);
    }

    /** Per PO line: ordered, previously received, scanned in this session, still open. */
    public function receivingStatus(ReceivingSession $s): array
    {
        $s->load('purchaseOrder.lines.product', 'scans');
        $out = [];
        foreach ($s->purchaseOrder->lines as $l) {
            $scanned = Money::of(Money::add(...($s->scans->where('purchase_order_line_id', $l->id)->pluck('qty')->all() ?: [0])));
            $open = Money::of($l->qty)->minus(Money::of($l->qty_received));
            $room = PurchaseOrderService::receivable($s->purchaseOrder, $l);
            $out[$l->id] = ['line' => $l, 'ordered' => Money::s($l->qty), 'received' => Money::s($l->qty_received), 'scanned' => Money::s($scanned),
                'remaining' => Money::s(BigDecimal::max($open->minus($scanned), BigDecimal::zero())), 'allowed' => Money::s($room->minus($scanned))];
        }

        return $out;
    }

    /**
     * 0.20.15 (#36): a GS1 scan fills lot (AI 10) and expiry (AI 17) and looks up the GTIN (AI 01); typed values win.
     * $trackLots = true switches lot tracking on for the product from this receipt on. $coaId links a COA to the lot.
     */
    public function scanReceive(ReceivingSession $s, string $code, mixed $qty = 1, ?string $lot = null, ?string $expires = null, bool $trackLots = false, ?int $coaId = null): array
    {
        if ($s->status !== 'open') {
            throw new WorkflowException('This receiving session is closed.');
        }
        $gs1 = Gs1::parse($code);
        if ($gs1) {
            $lot = trim((string) $lot) ?: $gs1['lot'];
            $expires = $expires ?: $gs1['expires'];
            if ($gs1['gtin']) {
                $g = $gs1['gtin']; // GTIN-14 with leading zeros = EAN-13 / UPC-A
                $code = collect([$g, substr($g, 1), substr($g, 2)])->first(fn ($c) => ProductLookup::resolve($c) !== null) ?? $g;
            }
        }
        $r = self::resolve($code);
        $p = $r['product'];
        $turnOn = $trackLots && ! $p->track_lots;
        if ($turnOn) {
            $p->track_lots = true; // saved with the scan below
        }
        if ($coaId && ! \App\Models\ProductCoa::where('id', $coaId)->where('product_id', $p->id)->exists()) {
            throw new WorkflowException("That COA is not for {$p->sku}.");
        }
        $q = self::baseQty($r, $qty);
        $lot = trim((string) $lot) ?: null;
        if ($p->track_lots && ! $lot) {
            throw new WorkflowException("{$p->sku} is lot-tracked: enter the lot number (and expiry) before scanning.");
        }
        if ($p->track_lots && $expires && \Carbon\CarbonImmutable::parse($expires)->isPast()) {
            throw new WorkflowException("Lot {$lot} expired on {$expires}; do not receive it.");
        }
        $st = collect($this->receivingStatus($s))->filter(fn ($r) => (int) $r['line']->product_id === (int) $p->id);
        if ($st->isEmpty()) {
            throw new WorkflowException("{$p->sku} {$p->name} is not on PO {$s->purchaseOrder->number}.");
        }
        $row = $st->first(fn ($x) => Money::of($x['remaining'])->isGreaterThanOrEqualTo($q)) ?? $st->first(fn ($x) => Money::of($x['allowed'])->isGreaterThanOrEqualTo($q));
        if (! $row) {
            $left = Money::s(Money::add(...$st->pluck('allowed')->all()));
            $pct = PurchaseOrderService::allowancePct($s->purchaseOrder);
            throw new WorkflowException("Over-receipt: {$p->sku} has only {$left} left to receive on this PO".(Money::of($pct)->isPositive() ? " (incl. {$pct}% allowance)." : '.'));
        }
        if ($turnOn) {
            $p->save();
        }
        $s->scans()->create(['purchase_order_line_id' => $row['line']->id, 'qty' => Money::s($q), 'code' => mb_substr($code, 0, 64),
            'lot_number' => $p->track_lots ? $lot : null, 'expires_on' => $p->track_lots ? ($expires ?: null) : null, 'unit_code' => $r['unit']?->code,
            'product_coa_id' => $p->track_lots ? $coaId : null]);
        $remaining = Money::of($row['remaining'])->minus($q);

        return ['product' => $p, 'qty' => Money::s($q), 'lot' => $p->track_lots ? $lot : null, 'expires' => $p->track_lots ? ($expires ?: null) : null, 'unit' => $r['unit']?->code, 'remaining' => Money::s(BigDecimal::max($remaining, BigDecimal::zero())),
            'over' => $remaining->isNegative() ? Money::s($remaining->negated()) : null];
    }

    public function undoLastScan(ReceivingSession $s): void
    {
        if ($s->status === 'open') {
            $s->scans()->latest('id')->first()?->delete();
        }
    }

    /** Post what was scanned: stock + GL via PurchaseOrderService, short lines recorded as variances, put-away tasks created. */
    public function postReceiving(ReceivingSession $s, ?User $by = null): ReceivingSession
    {
        if ($s->status !== 'open') {
            throw new WorkflowException('This receiving session is already closed.');
        }
        $status = $this->receivingStatus($s);
        $q = [];
        $variances = [];
        foreach ($status as $id => $r) {
            if (Money::of($r['scanned'])->isPositive()) {
                $q[$id] = $r['scanned'];
            }
            if (Money::of($r['remaining'])->isPositive()) {
                $variances[] = "{$r['line']->product->sku}: short {$r['remaining']} (ordered {$r['ordered']}, received {$r['received']} before, scanned {$r['scanned']})";
            }
        }
        if (! $q) {
            throw new WorkflowException('Nothing scanned yet.');
        }

        return DB::transaction(function () use ($s, $q, $variances, $status) {
            $po = $s->purchaseOrder;
            $this->po->receive($po, $q);
            foreach ($s->scans()->whereNotNull('lot_number')->get()->groupBy(fn ($x) => $x->purchase_order_line_id.'|'.$x->lot_number) as $grp) {
                $l = $status[$grp->first()->purchase_order_line_id]['line'];
                $this->lots->receive($l->product, $po->warehouse_id, $grp->first()->lot_number, $grp->first()->expires_on?->toDateString(),
                    Money::add(...$grp->pluck('qty')->all()), $po, $grp->whereNotNull('product_coa_id')->first()?->product_coa_id);
            }
            foreach ($q as $lineId => $qty) {
                $l = $status[$lineId]['line'];
                $bin = Inventory::where('product_id', $l->product_id)->where('warehouse_id', $po->warehouse_id)->value('bin_id');
                PutawayTask::create(['product_id' => $l->product_id, 'warehouse_id' => $po->warehouse_id, 'receiving_session_id' => $s->id,
                    'suggested_bin_id' => $bin, 'qty' => $qty]);
            }
            $s->update(['status' => 'posted', 'posted_at' => now(), 'variances' => $variances ?: null]);

            return $s;
        });
    }

    // ---------------- put-away ----------------

    public function putaway(PutawayTask $t, string $binCode, string $productCode, ?User $by = null): PutawayTask
    {
        if ($t->status !== 'open') {
            throw new WorkflowException('This put-away task is already done.');
        }
        $p = self::product($productCode);
        if ((int) $p->id !== (int) $t->product_id) {
            throw new WorkflowException("Wrong item: scanned {$p->sku}, task is for {$t->product->sku}.");
        }
        $bin = Bin::where('warehouse_id', $t->warehouse_id)->where('code', trim($binCode))->where('active', true)->first();
        if (! $bin) {
            throw new WorkflowException("Bin '$binCode' not found in {$t->warehouse->code}.");
        }

        return DB::transaction(function () use ($t, $bin, $by) {
            $inv = Inventory::firstOrCreate(['product_id' => $t->product_id, 'warehouse_id' => $t->warehouse_id], ['qty' => 0, 'reserved' => 0]);
            if (! $inv->bin_id) {
                $inv->update(['bin_id' => $bin->id]); // first put-away sets the primary pick bin
            }
            $t->update(['status' => 'done', 'bin_id' => $bin->id, 'done_by' => $by?->id, 'done_at' => now()]);

            return $t;
        });
    }

    // ---------------- picking ----------------

    public function pickList(SalesOrder $so): PickList
    {
        if ($so->status !== S::Confirmed && ! PickList::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->exists()) {
            throw new WorkflowException("Order {$so->number} is {$so->status->value}; confirm it before picking.");
        }
        $existing = PickList::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($so) {
            $pl = PickList::create(['number' => Sequence::next('PICK-', PickList::class), 'sales_order_id' => $so->id]);
            foreach ($so->lines()->with('product')->get() as $l) {
                $bin = Inventory::where('product_id', $l->product_id)->where('warehouse_id', $so->warehouse_id)->value('bin_id');
                $line = $pl->lines()->create(['sales_order_line_id' => $l->id, 'product_id' => $l->product_id, 'bin_id' => $bin, 'qty' => $l->qty]);
                $this->lots->allocate($line, $so->warehouse_id); // FEFO
            }

            return $pl;
        });
    }

    /** Lines in walk order: bin pick sequence, bin code, then SKU (unbinned last). */
    public function orderedLines(PickList $pl)
    {
        return $pl->lines()->with('product', 'bin', 'lots.lot')->get()->sortBy(fn (PickListLine $l) => [
            $l->bin ? 0 : 1, $l->bin?->pick_sequence ?? 0, $l->bin?->code ?? '', $l->product->sku,
        ])->values();
    }

    public function scanPick(PickList $pl, string $code, mixed $qty = 1, ?string $lot = null): PickListLine
    {
        if ($pl->status !== 'open') {
            throw new WorkflowException("Pick list {$pl->number} is {$pl->status}.");
        }
        $r = self::resolve($code);
        $p = $r['product'];
        $q = self::baseQty($r, $qty);
        $lines = $pl->lines()->where('product_id', $p->id)->get();
        if ($lines->isEmpty()) {
            throw new WorkflowException("Wrong item: {$p->sku} {$p->name} is not on this order.");
        }
        $line = $lines->first(fn ($l) => Money::of($l->qty)->minus(Money::of($l->qty_picked))->isGreaterThanOrEqualTo($q));
        if (! $line) {
            throw new WorkflowException("Over-pick: {$p->sku} needs only ".Money::s(Money::add(...$lines->map(fn ($l) => Money::s(Money::of($l->qty)->minus(Money::of($l->qty_picked))))->all())).' more.');
        }
        DB::transaction(function () use ($line, $lot, $q) {
            $this->lots->pick($line, $lot, $q);
            $line->update(['qty_picked' => Money::add($line->qty_picked, $q)]);
        });

        return $line;
    }

    /**
     * Short pick: cut the order line down to what was picked (or a lower qty). Needs a manager/admin approver, always logged.
     */
    public function shortPick(PickListLine $line, ?User $by, User $approver, string $reason, mixed $newQty = null): PickListLine
    {
        $pl = $line->pickList;
        if ($pl->status !== 'open') {
            throw new WorkflowException("Pick list {$pl->number} is {$pl->status}.");
        }
        if (! $approver->isAdmin()) {
            throw new WorkflowException('Short picks need a manager or admin to approve.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('Give a reason for the short pick.');
        }
        $new = Money::of($newQty ?? $line->qty_picked);
        if ($new->isLessThan(Money::of($line->qty_picked))) {
            throw new WorkflowException('Already picked '.Money::s($line->qty_picked).'; un-pick first to go lower.');
        }
        $so = $pl->salesOrder;
        $sol = $line->salesOrderLine;
        $old = Money::s($line->qty);

        return DB::transaction(function () use ($line, $so, $sol, $new, $old, $by, $approver, $reason) {
            $this->so->reduceLine($so, $sol, $new);
            \App\Models\WarehouseEvent::create(['type' => 'short_pick', 'user_id' => $by?->id, 'approved_by' => $approver->id, 'sales_order_id' => $so->id,
                'product_id' => $line->product_id, 'qty' => Money::s(Money::of($old)->minus($new)), 'reason' => mb_substr(trim($reason), 0, 255),
                'details' => ['pick_list' => $line->pickList->number, 'ordered' => $old, 'new_qty' => Money::s($new)]]);
            if ($new->isZero()) {
                $line->delete();
            } else {
                $line->update(['qty' => Money::s($new)]);
                if ($line->product->track_lots) {
                    $over = Money::of(Money::add(...($line->lots()->pluck('qty_allocated')->all() ?: [0])))->minus($new);
                    foreach ($line->lots()->with('lot')->get()->sortByDesc(fn ($a) => $a->lot->expires_on?->toDateString() ?? '9999') as $a) {
                        if (! $over->isPositive()) {
                            break;
                        }
                        $free = Money::of($a->qty_allocated)->minus(Money::of($a->qty_picked));
                        $cut = BigDecimal::min($free, $over);
                        $a->update(['qty_allocated' => Money::s(Money::of($a->qty_allocated)->minus($cut))]);
                        $over = $over->minus($cut);
                    }
                }
            }

            return $line;
        });
    }

    public function completePick(PickList $pl, ?User $by = null): PickList
    {
        if ($pl->status !== 'open') {
            throw new WorkflowException("Pick list {$pl->number} is {$pl->status}.");
        }
        $short = $pl->lines()->with('product')->get()->filter(fn ($l) => Money::of($l->qty_picked)->isLessThan(Money::of($l->qty)));
        if ($short->isNotEmpty()) {
            throw new WorkflowException('Not fully picked: '.$short->map(fn ($l) => "{$l->product->sku} ".Money::s($l->qty_picked).'/'.Money::s($l->qty))->implode(', ')
                .'. Use Short pick (manager approval) if the stock is not there.');
        }

        return DB::transaction(function () use ($pl, $by) {
            $this->so->pick($pl->salesOrder);
            $pl->update(['status' => 'picked', 'picker_id' => $by?->id, 'picked_at' => now()]);

            return $pl;
        });
    }

    // ---------------- packing ----------------

    public function currentPackage(PickList $pl): Package
    {
        return $pl->packages()->where('status', 'open')->latest('seq')->first()
            ?? $pl->packages()->create(['seq' => ((int) $pl->packages()->max('seq')) + 1]);
    }

    public function scanPack(PickList $pl, string $code, mixed $qty = 1): Package
    {
        if ($pl->status !== 'picked') {
            throw new WorkflowException("Pick list {$pl->number} must be picked before packing (it is {$pl->status}).");
        }
        $r = self::resolve($code);
        $p = $r['product'];
        $q = self::baseQty($r, $qty);
        $lines = $pl->lines()->where('product_id', $p->id)->get();
        if ($lines->isEmpty()) {
            throw new WorkflowException("Wrong item: {$p->sku} is not on this order.");
        }
        $line = $lines->first(fn ($l) => Money::of($l->qty_picked)->minus(Money::of($l->qty_packed))->isGreaterThanOrEqualTo($q));
        if (! $line) {
            throw new WorkflowException("{$p->sku} is already fully packed.");
        }

        return DB::transaction(function () use ($pl, $line, $q) {
            $pkg = $this->currentPackage($pl);
            $pkg->lines()->create(['pick_list_line_id' => $line->id, 'qty' => Money::s($q)]);
            $line->update(['qty_packed' => Money::add($line->qty_packed, $q)]);

            return $pkg;
        });
    }

    public function closePackage(PickList $pl, mixed $weight = null): ?Package
    {
        $pkg = $pl->packages()->where('status', 'open')->latest('seq')->first();
        if (! $pkg || ! $pkg->lines()->exists()) {
            throw new WorkflowException('The open carton is empty.');
        }
        $pkg->update(['status' => 'closed', 'weight_lb' => $weight !== null && $weight !== '' ? $weight : null]);

        return $pkg;
    }

    public function completePack(PickList $pl): PickList
    {
        if ($pl->status !== 'picked') {
            throw new WorkflowException("Pick list {$pl->number} is {$pl->status}.");
        }
        $left = $pl->lines()->with('product')->get()->filter(fn ($l) => Money::of($l->qty_packed)->isLessThan(Money::of($l->qty_picked)));
        if ($left->isNotEmpty()) {
            throw new WorkflowException('Not fully packed: '.$left->map(fn ($l) => "{$l->product->sku} ".Money::s($l->qty_packed).'/'.Money::s($l->qty_picked))->implode(', '));
        }

        return DB::transaction(function () use ($pl) {
            $pl->packages()->where('status', 'open')->get()->each(fn ($p) => $p->lines()->exists() ? $p->update(['status' => 'closed']) : $p->delete());
            $pl->update(['status' => 'packed', 'packed_at' => now()]);

            return $pl;
        });
    }
}
