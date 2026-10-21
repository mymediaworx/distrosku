<?php

namespace App\Imports\Types;

use App\Imports\RowResult;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ScheduledCost;
use App\Models\VendorItem;
use App\Services\Money;
use App\Warehouse\ProductLookup;

/**
 * Vendor price file: match by vendor item # (per vendor), then UPC; show old vs new cost and % change; apply now or
 * schedule on the batch's effective date (scheduled_costs, applied by costs:apply). Unmatched rows are flagged, not errors.
 */
class VendorCostsImport extends ImportType
{
    public function key(): string
    {
        return 'vendor_costs';
    }

    public function label(): string
    {
        return 'Vendor price file (costs)';
    }

    public function needsVendor(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'vendor_item_no' => ['label' => 'Vendor item #', 'example' => 'A-10023'],
            'upc' => ['label' => 'UPC', 'example' => '028200003577'],
            'cost' => ['label' => 'New cost', 'required' => true, 'example' => '58.25'],
            'description' => ['label' => 'Description (shown for unmatched items)'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        $item = static::s($v, 'vendor_item_no');
        $upc = static::s($v, 'upc');
        if (! $item && ! $upc) {
            $e[] = 'Vendor item # or UPC is required.';
        }
        $cost = static::num($v, 'cost', $e, 'New cost');
        if (static::s($v, 'cost') === null) {
            $e[] = 'New cost is required.';
        }
        $vi = $item ? VendorItem::with('product')->where('vendor_id', $b->vendor_id)->where('vendor_item_no', $item)->first() : null;
        $p = $vi?->product ?? ($upc ? ProductLookup::find($upc) : null);
        $label = $item ?? $upc;
        if (! $p) {
            return new RowResult($e, null, [], $label, ['desc' => static::s($v, 'description')], unmatched: ! $e);
        }
        $old = Money::s($p->cost);
        $diff = [];
        if ($cost !== null && ! Money::of($old)->isEqualTo(Money::of($cost))) {
            $pct = (float) $old > 0 ? round(((float) $cost - (float) $old) / (float) $old * 100, 2) : null;
            $diff['cost'] = [$old, $cost, $pct];
        }
        $future = $b->effective_on && $b->effective_on->isAfter(today());
        $action = $diff ? ($future ? 'schedule' : 'update') : 'unchanged';

        return new RowResult($e, $action, $diff, "{$p->sku}", ['product' => $p, 'cost' => $cost, 'item' => $item, 'vi' => $vi, 'future' => $future]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $c = $r->context;
        /** @var Product $p */
        $p = $c['product'];
        if ($c['item'] && ! $c['vi']) {
            VendorItem::firstOrCreate(['vendor_id' => $b->vendor_id, 'vendor_item_no' => $c['item']], ['product_id' => $p->id]);
        }
        VendorItem::where('vendor_id', $b->vendor_id)->where('product_id', $p->id)
            ->when($c['item'], fn ($q) => $q->where('vendor_item_no', $c['item']))->update(['last_cost' => $c['cost'], 'last_cost_on' => ($b->effective_on ?? today())->toDateString(), 'updated_at' => now()]);
        if ($r->action === 'schedule') {
            ScheduledCost::create(['product_id' => $p->id, 'vendor_id' => $b->vendor_id, 'import_batch_id' => $b->id, 'old_cost' => $p->cost, 'cost' => $c['cost'], 'effective_on' => $b->effective_on]);
        } elseif ($r->action === 'update') {
            $p->update(['cost' => $c['cost']]);
        }

        return $r->action;
    }
}
