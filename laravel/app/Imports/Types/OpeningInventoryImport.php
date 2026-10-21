<?php

namespace App\Imports\Types;

use App\Imports\RowResult;
use App\Models\ImportBatch;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\CustomerBalanceService;
use App\Services\InventoryService;
use App\Services\Money;
use App\Services\PostingService;
use App\Warehouse\ProductLookup;

/** Sets on-hand qty per product + warehouse (moves the difference); posts one GL entry Dr 1200 / Cr 3900 for the value. */
class OpeningInventoryImport extends ImportType
{
    public function key(): string
    {
        return 'opening_inventory';
    }

    public function label(): string
    {
        return 'Opening inventory';
    }

    public function fields(): array
    {
        return [
            'product' => ['label' => 'SKU or UPC', 'required' => true, 'example' => 'MARL-RED-KS'],
            'warehouse' => ['label' => 'Warehouse code', 'required' => true, 'example' => 'MAIN'],
            'qty' => ['label' => 'Quantity on hand (base units)', 'required' => true, 'example' => '120'],
            'unit_cost' => ['label' => 'Unit cost (blank = product cost)'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['product' => 'SKU or UPC', 'warehouse' => 'Warehouse code', 'qty' => 'Quantity'], $e);
        $p = ($code = static::s($v, 'product')) ? ProductLookup::find($code) : null;
        if ($code && ! $p) {
            $e[] = "No product for \"{$code}\".";
        }
        if ($p?->track_lots) {
            $e[] = "{$p->sku} is lot-tracked: enter its opening stock through receiving so each lot and expiry is recorded.";
        }
        $w = ($wc = static::s($v, 'warehouse')) ? Warehouse::where('code', $wc)->first() : null;
        if ($wc && ! $w) {
            $e[] = "Warehouse \"{$wc}\" does not exist.";
        }
        $qty = static::num($v, 'qty', $e, 'Quantity');
        $cost = static::num($v, 'unit_cost', $e, 'Unit cost');
        $cur = $p && $w ? (string) (Inventory::where('product_id', $p->id)->where('warehouse_id', $w->id)->value('qty') ?? '0') : '0';
        $diff = $qty !== null && ! Money::of($cur)->isEqualTo(Money::of($qty)) ? ['qty' => [Money::s($cur), $qty]] : [];

        return new RowResult($e, $diff ? ((float) $cur == 0.0 ? 'create' : 'update') : 'unchanged', $diff, $p && $w ? "{$p->sku}@{$w->code}" : null,
            ['product' => $p, 'warehouse' => $w, 'qty' => $qty, 'cur' => $cur, 'cost' => $cost ?? ($p ? Money::s($p->cost) : '0')]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $c = $r->context;
        $delta = Money::of($c['qty'])->minus(Money::of($c['cur']));
        if (! $delta->isZero()) {
            app(InventoryService::class)->move($c['product'], $c['warehouse'], Money::s($delta), 'adjustment', $b, $c['cost'], "Opening inventory import #{$b->id}");
        }

        return $r->action;
    }

    public function finish(ImportBatch $b): void
    {
        $value = StockMovement::where('reference_type', $b->getMorphClass())->where('reference_id', $b->id)
            ->get()->reduce(fn ($s, $m) => $s->plus(Money::of($m->qty)->multipliedBy(Money::of($m->unit_cost))), Money::of(0));
        if ($value->isZero()) {
            return;
        }
        $amt = Money::s($value->abs());
        $lines = $value->isPositive() ? [[PostingService::INVENTORY, $amt, 0], [CustomerBalanceService::OPENING_EQUITY, 0, $amt]]
            : [[CustomerBalanceService::OPENING_EQUITY, $amt, 0], [PostingService::INVENTORY, 0, $amt]];
        app(PostingService::class)->post(now()->toDateString(), "Opening inventory import #{$b->id}", $lines, $b);
    }
}
