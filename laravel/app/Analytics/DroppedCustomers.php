<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 0.20.17 "Customers dropped off": active customers who HAVE bought before (at least one invoiced order) but have no order or
 * invoice in the last N days. Last activity = the latest of invoice date / order date over confirmed, picked, delivered and
 * invoiced orders (drafts and cancelled never count). POS and van sales are sales orders, so they count too. Inactive customers
 * are excluded. Windows are cumulative: the 60-day list contains everyone in the 90-, 120- and 180-day lists.
 */
final class DroppedCustomers
{
    public const WINDOWS = [60, 90, 120, 180];

    /** @return list<object{customer_id:int, last_activity:string}> every active customer with a prior invoiced order and their last activity */
    public static function activity(): array
    {
        $so = DB::table('sales_orders')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->groupBy('customer_id')
            ->selectRaw("customer_id, MAX(COALESCE(invoiced_at, created_at)) as last_activity, SUM(CASE WHEN status = 'invoiced' THEN 1 ELSE 0 END) as invoiced_count");

        return DB::table('customers')->joinSub($so, 'a', 'a.customer_id', '=', 'customers.id')
            ->where('customers.active', true)->where('a.invoiced_count', '>', 0)
            ->get(['customers.id as customer_id', 'a.last_activity'])->all();
    }

    /** @return array<int,int> window days => customer count */
    public static function counts(): array
    {
        $rows = self::activity();
        $out = [];
        foreach (self::WINDOWS as $w) {
            $cut = CarbonImmutable::now()->subDays($w);
            $out[$w] = count(array_filter($rows, fn ($r) => CarbonImmutable::parse($r->last_activity)->lt($cut)));
        }

        return $out;
    }

    public static function normalizeWindow(mixed $w): int
    {
        return in_array((int) $w, self::WINDOWS, true) ? (int) $w : 90;
    }

    /** Management-report array (same shape as ReportService reports). */
    public static function report(int $window): array
    {
        $window = self::normalizeWindow($window);
        $cut = CarbonImmutable::now()->subDays($window);
        $last = collect(self::activity())->filter(fn ($r) => CarbonImmutable::parse($r->last_activity)->lt($cut))->keyBy('customer_id');
        $customers = \App\Models\Customer::query()->with('salesRep')->whereIn('id', $last->keys()->all())->get();
        $rows = $customers->map(function ($c) use ($last) {
            $d = CarbonImmutable::parse($last[$c->id]->last_activity);

            return ['d' => $d, 'row' => [$c->code, $c->name, $c->salesRep?->name ?? '', $d->format('m/d/Y'), (string) (int) $d->diffInDays(now())]];
        })->sortBy(fn ($x) => $x['d']->timestamp)->pluck('row')->values()->all();

        return [
            'key' => 'customers_dropped_off', 'title' => "Customers dropped off ({$window}+ days)", 'period' => 'As of '.now()->format('m/d/Y'),
            'columns' => ['Code', 'Customer', 'Sales rep', 'Last order', 'Days since'],
            'rows' => $rows, 'totals' => ['Customers', (string) count($rows), '', '', ''],
            'notes' => ['Active customers who have bought before but have no order or invoice in the last '.$window.' days (drafts and cancelled orders do not count). Windows are cumulative: the 60-day list includes everyone in the 90/120/180-day lists. Inactive customers are excluded.'],
        ];
    }
}
