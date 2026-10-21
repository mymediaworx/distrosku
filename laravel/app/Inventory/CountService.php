<?php

namespace App\Inventory;

use App\Exceptions\WorkflowException;
use App\Models\Inventory;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\Lot;
use App\Models\LotStock;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\Money;
use App\Services\PostingService;
use App\Services\Sequence;
use App\Warehouse\LotService;
use App\Warehouse\ProductLookup;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Cycle and full counts. Starting a count snapshots expected qty (per lot for lot-tracked items) and FREEZES the items:
 * a full count freezes the whole warehouse, a cycle count freezes the items in scope. No stock can move until the count is
 * posted or cancelled. Variances need owner/admin approval; posting adjusts stock + lots and books Inventory vs 5200.
 */
class CountService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting, private LotService $lots) {}

    /** The active count freezing this item, if any. */
    public static function freezingCount(int $productId, int $warehouseId): ?InventoryCount
    {
        return InventoryCount::where('warehouse_id', $warehouseId)->whereIn('status', ['counting', 'submitted'])
            ->where(fn ($q) => $q->where('type', 'full')->orWhereHas('lines', fn ($l) => $l->where('product_id', $productId)))
            ->first();
    }

    /** @param array{bin_ids?: int[], product_ids?: int[]} $scope */
    public function create(Warehouse $wh, string $type, bool $blind, array $scope = [], ?User $by = null, ?string $notes = null): InventoryCount
    {
        if (! in_array($type, ['cycle', 'full'], true)) {
            throw new WorkflowException('Count type must be cycle or full.');
        }
        $scope = ['bin_ids' => array_values(array_map('intval', $scope['bin_ids'] ?? [])), 'product_ids' => array_values(array_map('intval', $scope['product_ids'] ?? []))];
        if ($type === 'cycle' && ! $scope['bin_ids'] && ! $scope['product_ids']) {
            throw new WorkflowException('A cycle count needs bins or items in scope.');
        }

        return InventoryCount::create(['number' => Sequence::next('CNT-', InventoryCount::class), 'warehouse_id' => $wh->id, 'type' => $type,
            'blind' => $blind, 'scope' => $scope, 'created_by' => $by?->id, 'notes' => $notes, 'status' => 'draft'])->fresh();
    }

    /** Snapshot expected quantities and freeze. */
    public function start(InventoryCount $c): InventoryCount
    {
        if ($c->status !== 'draft') {
            throw new WorkflowException("Count {$c->number} is {$c->status}.");
        }
        if ($c->type === 'full' && InventoryCount::where('warehouse_id', $c->warehouse_id)->whereIn('status', ['counting', 'submitted'])->exists()) {
            throw new WorkflowException('Another count is open in this warehouse; finish it before a full count.');
        }

        return DB::transaction(function () use ($c) {
            $q = Inventory::with('product')->where('warehouse_id', $c->warehouse_id);
            if ($c->type === 'cycle') {
                $q->where(fn ($w) => $w->whereIn('bin_id', $c->scope['bin_ids'] ?: [0])->orWhereIn('product_id', $c->scope['product_ids'] ?: [0]));
            }
            $rows = $q->get();
            if ($c->type === 'cycle') {
                foreach (array_diff($c->scope['product_ids'], $rows->pluck('product_id')->all()) as $pid) {
                    $rows->push(new Inventory(['product_id' => $pid, 'warehouse_id' => $c->warehouse_id, 'qty' => 0]));
                }
            }
            foreach ($rows as $inv) {
                $p = $inv->product ?? Product::find($inv->product_id);
                if (! $p) {
                    continue;
                }
                if ($c->type === 'cycle' && ($other = self::freezingCount($p->id, $c->warehouse_id))) {
                    throw new WorkflowException("{$p->sku} is already frozen by count {$other->number}.");
                }
                $this->snapshot($c, $p, $inv->bin_id, Money::of($inv->qty ?? 0));
            }
            if (! $c->lines()->exists() && $c->type === 'cycle') {
                throw new WorkflowException('Nothing in scope to count.');
            }
            $c->update(['status' => 'counting', 'started_at' => now()]);

            return $c;
        });
    }

    private function snapshot(InventoryCount $c, Product $p, ?int $binId, BigDecimal $onHand): void
    {
        $base = ['inventory_count_id' => $c->id, 'product_id' => $p->id, 'bin_id' => $binId, 'unit_cost' => Money::s($p->cost ?? 0)];
        $lotted = BigDecimal::zero();
        if ($p->track_lots) {
            $stocks = LotStock::with('lot')->where('warehouse_id', $c->warehouse_id)->where('qty', '>', 0)->whereHas('lot', fn ($l) => $l->where('product_id', $p->id))->get();
            foreach ($stocks as $s) {
                InventoryCountLine::create($base + ['lot_id' => $s->lot_id, 'expected' => Money::s($s->qty)]);
                $lotted = $lotted->plus(Money::of($s->qty));
            }
        }
        $unlotted = $onHand->minus($lotted);
        if (! $p->track_lots || $unlotted->isPositive() || $lotted->isZero()) {
            InventoryCountLine::create($base + ['lot_id' => null, 'expected' => Money::s(BigDecimal::max($unlotted, BigDecimal::zero()))]);
        }
    }

    private function line(InventoryCount $c, Product $p, ?string $lotNumber, ?string $expires): InventoryCountLine
    {
        $lines = $c->lines()->where('product_id', $p->id)->get();
        if ($lines->isEmpty() && $c->type === 'cycle') {
            throw new WorkflowException("{$p->sku} is not in this cycle count.");
        }
        if ($p->track_lots && ($lotNumber = trim((string) $lotNumber)) !== '') {
            $lot = Lot::where('product_id', $p->id)->where('lot_number', $lotNumber)->first();
            if (! $lot) {
                $lot = Lot::create(['product_id' => $p->id, 'lot_number' => $lotNumber, 'expires_on' => $expires ?: null]); // found stock of a new lot
            }
            $line = $lines->firstWhere('lot_id', $lot->id);
        } elseif ($p->track_lots && $lines->whereNotNull('lot_id')->isNotEmpty()) {
            throw new WorkflowException("{$p->sku} is lot-tracked: enter the lot number.");
        } else {
            $lot = null;
            $line = $lines->firstWhere('lot_id', null);
        }

        return $line ?? InventoryCountLine::create(['inventory_count_id' => $c->id, 'product_id' => $p->id, 'lot_id' => $lot?->id, 'expected' => 0,
            'bin_id' => $lines->first()?->bin_id ?? Inventory::where('product_id', $p->id)->where('warehouse_id', $c->warehouse_id)->value('bin_id'),
            'unit_cost' => Money::s($p->cost ?? 0)]);
    }

    private function assertCounting(InventoryCount $c): void
    {
        if ($c->status !== 'counting') {
            throw new WorkflowException("Count {$c->number} is {$c->status}; counting is closed.");
        }
    }

    /** Add a scan: qty x unit factor of the scanned barcode. */
    public function scan(InventoryCount $c, string $code, mixed $qty = 1, ?string $lot = null, ?string $expires = null, ?User $by = null): InventoryCountLine
    {
        $this->assertCounting($c);
        $r = ProductLookup::resolve($code);
        if (! $r) {
            throw new WorkflowException("Unknown barcode or SKU '$code'.");
        }
        $q = Money::of($qty === '' || $qty === null ? 1 : $qty)->multipliedBy(Money::of($r['factor']))->toScale(4);
        if ($q->isNegativeOrZero()) {
            throw new WorkflowException('Quantity must be positive.');
        }

        return DB::transaction(function () use ($c, $r, $q, $lot, $expires, $by, $code) {
            $line = $this->line($c, $r['product'], $lot, $expires);
            $line->scans()->create(['code' => mb_substr($code, 0, 64), 'unit_code' => $r['unit']?->code, 'qty' => Money::s($q), 'user_id' => $by?->id]);
            $line->update(['counted' => Money::add($line->counted ?? 0, $q), 'counted_by' => $by?->id, 'counted_at' => now()]);

            return $line;
        });
    }

    /** Type a total for a line (recount / manual entry). */
    public function setCounted(InventoryCountLine $line, mixed $qty, ?User $by = null): InventoryCountLine
    {
        $this->assertCounting($line->inventoryCount);
        $new = Money::of($qty);
        if ($new->isNegative()) {
            throw new WorkflowException('Counted quantity cannot be negative.');
        }
        $line->scans()->create(['code' => 'manual', 'qty' => Money::s($new->minus(Money::of($line->counted ?? 0))), 'user_id' => $by?->id]);
        $line->update(['counted' => Money::s($new), 'counted_by' => $by?->id, 'counted_at' => now()]);

        return $line;
    }

    public function undoLastScan(InventoryCount $c): void
    {
        $this->assertCounting($c);
        $s = \App\Models\InventoryCountScan::whereHas('line', fn ($l) => $l->where('inventory_count_id', $c->id))->latest('id')->first();
        if ($s) {
            $line = $s->line;
            $left = Money::of($line->counted ?? 0)->minus(Money::of($s->qty));
            $line->update(['counted' => $line->scans()->count() > 1 ? Money::s(BigDecimal::max($left, BigDecimal::zero())) : null]);
            $s->delete();
        }
    }

    /** Close counting. Uncounted lines must be zeroed explicitly. */
    public function submit(InventoryCount $c, bool $zeroUncounted = false, ?User $by = null): InventoryCount
    {
        $this->assertCounting($c);
        $open = $c->lines()->whereNull('counted')->with('product')->get();
        if ($open->isNotEmpty() && ! $zeroUncounted) {
            throw new WorkflowException('Not counted yet: '.$open->take(10)->map(fn ($l) => $l->product->sku)->implode(', ').($open->count() > 10 ? '…' : '')
                .'. Count them or submit with "treat uncounted as zero".');
        }
        DB::transaction(function () use ($c, $open, $by) {
            foreach ($open as $l) {
                $l->update(['counted' => 0, 'counted_by' => $by?->id, 'counted_at' => now()]);
            }
            $c->update(['status' => 'submitted', 'submitted_by' => $by?->id, 'submitted_at' => now(),
                'variance_value' => Money::add(...($c->lines()->get()->map->varianceValue()->all() ?: [0]))]);
        });
        if ($this->withinAutoApprove($c)) {
            $this->post($c, null, null, true);
            \App\Models\WarehouseEvent::create(['type' => 'count_auto_approve', 'user_id' => $by?->id, 'reason' => 'Every line within $'.self::autoValue().' or '.self::autoPct().'%',
                'qty' => 0, 'details' => ['count_id' => $c->id, 'count' => $c->number, 'variance_value' => (string) $c->fresh()->variance_value,
                    'lines' => $c->lines()->with('product')->get()->filter(fn ($l) => ! Money::of($l->variance())->isZero())
                        ->map(fn ($l) => ['sku' => $l->product->sku, 'expected' => (string) $l->expected, 'counted' => (string) $l->counted, 'value' => $l->varianceValue()])->values()->all()]]);
        }

        return $c->fresh();
    }

    public static function autoValue(): string
    {
        return Money::s(\App\Models\Setting::get('count_auto_approve_value', 25) ?? 0);
    }

    public static function autoPct(): string
    {
        return Money::s(\App\Models\Setting::get('count_auto_approve_pct', 2) ?? 0);
    }

    /** True when every line's variance is within $X (value) OR Y% of the expected qty. Defaults $25 / 2%; 0 and 0 = always admin. */
    public function withinAutoApprove(InventoryCount $c): bool
    {
        $maxV = Money::of(self::autoValue());
        $maxP = Money::of(self::autoPct());
        if (! $maxV->isPositive() && ! $maxP->isPositive()) {
            return false; // auto-approve switched off
        }
        foreach ($c->lines()->get() as $l) {
            $var = Money::of($l->variance())->abs();
            if ($var->isZero()) {
                continue;
            }
            $okValue = $maxV->isPositive() && Money::of($l->varianceValue())->abs()->isLessThanOrEqualTo($maxV);
            $exp = Money::of($l->expected)->abs();
            $okPct = $maxP->isPositive() && $exp->isPositive() && $var->multipliedBy(100)->isLessThanOrEqualTo($exp->multipliedBy($maxP));
            if (! $okValue && ! $okPct) {
                return false;
            }
        }

        return true;
    }

    /** Send a submitted count back for recounting (admin). */
    public function reopen(InventoryCount $c, User $by): InventoryCount
    {
        if ($c->status !== 'submitted' || ! $by->isAdmin()) {
            throw new WorkflowException('Only an admin can reopen a submitted count.');
        }
        $c->update(['status' => 'counting', 'submitted_at' => null, 'submitted_by' => null]);

        return $c;
    }

    /** Approve and post the variances: stock moves, lot adjustments, one GL journal. Unfreezes. */
    public function approve(InventoryCount $c, User $approver, ?string $date = null): InventoryCount
    {
        if ($c->status !== 'submitted') {
            throw new WorkflowException("Count {$c->number} must be submitted before approval (it is {$c->status}).");
        }
        if (! $approver->isAdmin()) {
            throw new WorkflowException('Count variances need an owner or admin to approve.');
        }

        return $this->post($c, $approver, $date);
    }

    private function post(InventoryCount $c, ?User $approver, ?string $date = null, bool $auto = false): InventoryCount
    {
        $date ??= now()->toDateString();

        return DB::transaction(function () use ($c, $approver, $date, $auto) {
            $c->load('lines.product', 'lines.lot', 'warehouse');
            $gain = BigDecimal::zero();
            $loss = BigDecimal::zero();
            foreach ($c->lines->groupBy('product_id') as $lines) {
                $p = $lines->first()->product;
                $delta = Money::of(Money::add(...$lines->map->variance()->all()));
                foreach ($lines as $l) {
                    if ($l->lot && ! Money::of($l->variance())->isZero()) {
                        $this->lots->adjust($l->lot, $c->warehouse_id, $l->variance(), "Count {$c->number}", $approver?->id);
                    }
                }
                if ($delta->isZero()) {
                    continue;
                }
                $cost = $lines->first()->unit_cost;
                $this->inventory->move($p, $c->warehouse, $delta, 'count', $c, $cost, "Count {$c->number}");
                $value = $delta->multipliedBy(Money::of($cost))->toScale(4, \Brick\Math\RoundingMode::HALF_UP);
                $value->isPositive() ? $gain = $gain->plus($value) : $loss = $loss->plus($value->negated());
            }
            $j = ($gain->isZero() && $loss->isZero()) ? null
                : $this->posting->countVariance($c, $date, Money::s($gain), Money::s($loss), "Inventory count {$c->number} ({$c->type}) variance");
            $c->update(['status' => 'posted', 'approved_by' => $approver?->id, 'auto_approved' => $auto, 'posted_at' => now(), 'journal_id' => $j?->id,
                'variance_value' => Money::s($gain->minus($loss))]);

            return $c;
        });
    }

    public function cancel(InventoryCount $c, User $by): InventoryCount
    {
        if (! in_array($c->status, ['draft', 'counting', 'submitted'], true)) {
            throw new WorkflowException("Count {$c->number} is {$c->status}.");
        }
        if ($c->status !== 'draft' && ! $by->isAdmin()) {
            throw new WorkflowException('Only an admin can cancel a started count.');
        }
        $c->update(['status' => 'cancelled']);

        return $c;
    }

    /** Can this user see expected quantities? (blind counts hide them from counters until submitted) */
    public static function showExpected(InventoryCount $c, ?User $u): bool
    {
        return ! $c->blind || (bool) $u?->isAdmin();
    }
}
