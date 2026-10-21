<?php

namespace App\Delivery;

use App\Enums\SalesOrderStatus as S;
use App\Models\DeliveryManifest;
use App\Models\DeliveryStop;
use App\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Route planning off existing manifests: confirmed/picked orders for the warehouse whose ship-to (or customer)
 * delivers on that weekday and that are not on another open stop. Ordered by route name, route sequence, city, name.
 */
class RoutePlanner
{
    /** @return Collection<int, SalesOrder> */
    public function candidates(string $date, int $warehouseId, ?string $route = null, bool $ignoreDays = false): Collection
    {
        $day = DeliveryDays::key(Carbon::parse($date));
        $busy = DeliveryStop::whereIn('status', ['pending', 'delivered', 'partial'])->pluck('sales_order_id');

        return SalesOrder::with('customer', 'shipTo')
            ->whereIn('status', [S::Confirmed, S::Picked])->where('warehouse_id', $warehouseId)
            ->where(fn ($q) => $q->whereNull('channel')->orWhereNotIn('channel', ['pos', 'van']))
            ->whereNotIn('id', $busy)->get()
            ->filter(fn ($so) => $ignoreDays || in_array($day, DeliveryDays::effective($so->shipTo, $so->customer), true))
            ->filter(fn ($so) => $route === null || $route === '' || strcasecmp((string) $so->shipTo?->route_name, $route) === 0)
            ->sortBy(fn ($so) => sprintf('%s|%06d|%s|%s', $so->shipTo?->route_name ?? '~', $so->shipTo?->route_sequence ?? 999999, $so->shipTo?->city, $so->customer?->name))
            ->values();
    }

    /** Adds candidates to the manifest after its existing stops; returns stops added. */
    public function plan(DeliveryManifest $m, ?string $route = null, bool $ignoreDays = false): int
    {
        return DB::transaction(function () use ($m, $route, $ignoreDays) {
            $seq = (int) $m->stops()->max('sequence');
            $n = 0;
            foreach ($this->candidates($m->delivery_date->toDateString(), $m->warehouse_id, $route ?? $m->route_name, $ignoreDays) as $so) {
                $m->stops()->create(['sales_order_id' => $so->id, 'sequence' => ++$seq, 'status' => 'pending']);
                $n++;
            }

            return $n;
        });
    }

    /** Re-number after a manual reorder: ids in new order. */
    public function resequence(DeliveryManifest $m, array $stopIds): void
    {
        foreach (array_values($stopIds) as $i => $id) {
            $m->stops()->whereKey($id)->update(['sequence' => $i + 1]);
        }
    }
}
