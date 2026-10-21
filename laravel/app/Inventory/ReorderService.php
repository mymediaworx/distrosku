<?php

namespace App\Inventory;

use App\Exceptions\WorkflowException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Services\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Reorder suggestions per warehouse.
 *   velocity      = units sold over the last N days / N               (N = Settings "velocity window", default 30)
 *   lead time     = product lead time, else vendor lead time, else Settings default (7 days)
 *   reorder level = max(reorder point, velocity x lead time)
 *   position      = on hand - reserved + on order (open PO qty not yet received, drafts included)
 *   suggest when position <= reorder level; qty = reorder level + velocity x cover days - position,
 *   rounded up to the product's order multiple. Vendor = preferred vendor, else the vendor of the last PO.
 */
class ReorderService
{
    public static function settings(): array
    {
        return ['window' => max(1, (int) Setting::get('reorder_velocity_days', 30)), 'lead' => max(0, (int) Setting::get('reorder_default_lead_days', 7)),
            'cover' => max(0, (int) Setting::get('reorder_cover_days', 14))];
    }

    /** @return array<int, array<string, mixed>> */
    public function suggestions(int $warehouseId): array
    {
        $cfg = self::settings();
        $since = now()->subDays($cfg['window']);
        $sold = StockMovement::where('warehouse_id', $warehouseId)->where('type', 'sale')->where('created_at', '>=', $since)
            ->groupBy('product_id')->selectRaw('product_id, SUM(-qty) as q')->pluck('q', 'product_id');
        $onOrder = PurchaseOrderLine::join('purchase_orders as po', 'po.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('po.warehouse_id', $warehouseId)->whereIn('po.status', ['draft', 'ordered', 'partial'])
            ->groupBy('product_id')->selectRaw('product_id, SUM(purchase_order_lines.qty - purchase_order_lines.qty_received) as q')->pluck('q', 'product_id');
        $inv = Inventory::where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
        $ids = collect($sold->keys())->merge($inv->keys())->merge(Product::whereNotNull('reorder_point')->where('reorder_point', '>', 0)->pluck('id'))->unique();
        $products = Product::with('preferredVendor')->whereIn('id', $ids)->where('active', true)->whereNull('archived_at')->get();
        $lastVendor = PurchaseOrderLine::join('purchase_orders as po', 'po.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereIn('product_id', $products->pluck('id'))->orderBy('po.order_date')->orderBy('po.id')->pluck('po.vendor_id', 'product_id');
        $vendors = Vendor::whereIn('id', $lastVendor->values()->merge($products->pluck('preferred_vendor_id'))->filter()->unique())->get()->keyBy('id');

        $out = [];
        foreach ($products as $p) {
            $i = $inv->get($p->id);
            $avail = Money::of($i->qty ?? 0)->minus(Money::of($i->reserved ?? 0));
            $oo = BigDecimal::max(Money::of($onOrder[$p->id] ?? 0), BigDecimal::zero());
            $vel = Money::of($sold[$p->id] ?? 0)->dividedBy($cfg['window'], 4, RoundingMode::HALF_UP);
            $vendor = $p->preferredVendor ?? $vendors->get($lastVendor[$p->id] ?? 0);
            $lead = $p->lead_time_days ?? $vendor?->lead_time_days ?? $cfg['lead'];
            $level = BigDecimal::max(Money::of($p->reorder_point ?? 0), $vel->multipliedBy($lead)->toScale(0, RoundingMode::UP));
            $position = $avail->plus($oo);
            if ($level->isZero() && $vel->isZero()) {
                continue;
            }
            if ($position->isGreaterThan($level)) {
                continue;
            }
            $qty = $level->plus($vel->multipliedBy($cfg['cover']))->minus($position)->toScale(0, RoundingMode::UP);
            $mult = Money::of($p->reorder_qty ?? 0);
            if ($mult->isPositive()) {
                $qty = $qty->dividedBy($mult, 0, RoundingMode::UP)->multipliedBy($mult);
            }
            if (! $qty->isPositive()) {
                continue;
            }
            $out[] = ['product' => $p, 'vendor' => $vendor, 'on_hand' => Money::s($i->qty ?? 0), 'reserved' => Money::s($i->reserved ?? 0),
                'on_order' => Money::s($oo), 'velocity' => Money::s($vel), 'lead_days' => (int) $lead, 'reorder_level' => Money::s($level),
                'position' => Money::s($position), 'qty' => Money::s($qty), 'unit_cost' => $uc = $this->lastVendorPrice($p, $vendor), 'value' => Money::mul($qty, $uc)];
        }
        usort($out, fn ($a, $b) => [$a['vendor']?->name ?? "\u{FFFF}", $a['product']->sku] <=> [$b['vendor']?->name ?? "\u{FFFF}", $b['product']->sku]);

        return $out;
    }

    /** Last PO unit price for this product from this vendor (latest non-cancelled PO), falling back to the product cost. */
    public function lastVendorPrice(\App\Models\Product $p, $vendor): string
    {
        if ($vendor) {
            $price = \App\Models\PurchaseOrderLine::where('product_id', $p->id)
                ->whereHas('purchaseOrder', fn ($q) => $q->where('vendor_id', $vendor->id)->where('status', '!=', 'cancelled'))
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                ->orderByDesc('purchase_orders.order_date')->orderByDesc('purchase_order_lines.id')
                ->value('purchase_order_lines.unit_cost');
            if ($price !== null) {
                return Money::s($price);
            }
        }

        return Money::s($p->cost ?? 0);
    }

    /** One draft PO per vendor from the current suggestions (optionally only some vendors / products). @return PurchaseOrder[] */
    public function createDraftPos(int $warehouseId, ?array $vendorIds = null, ?array $productIds = null, ?array $qtyOverrides = null): array
    {
        $rows = collect($this->suggestions($warehouseId))->filter(fn ($r) => $r['vendor']
            && ($vendorIds === null || in_array($r['vendor']->id, $vendorIds))
            && ($productIds === null || in_array($r['product']->id, $productIds)));
        if ($rows->isEmpty()) {
            throw new WorkflowException('No suggestions with a vendor to order.');
        }

        return DB::transaction(function () use ($rows, $warehouseId, $qtyOverrides) {
            $pos = [];
            foreach ($rows->groupBy(fn ($r) => $r['vendor']->id) as $vendorId => $grp) {
                $po = PurchaseOrder::create(['vendor_id' => $vendorId, 'warehouse_id' => $warehouseId, 'order_date' => now()->toDateString(), 'status' => 'draft']);
                foreach ($grp as $r) {
                    $q = $qtyOverrides[$r['product']->id] ?? $r['qty'];
                    if (Money::of($q)->isPositive()) {
                        $po->lines()->create(['product_id' => $r['product']->id, 'qty' => Money::s($q), 'unit_cost' => $r['unit_cost']]);
                    }
                }
                app(\App\Services\PurchaseOrderService::class)->recalc($po->fresh());
                $pos[] = $po->fresh();
            }

            return $pos;
        });
    }
}
