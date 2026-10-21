<?php

namespace App\Analytics;

use App\Enums\SalesOrderStatus as S;
use App\Models\Product;
use App\Services\Money;
use App\Services\PostingService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Dashboard figures. Cached 30s so polling widgets on many screens don't hammer MySQL. */
class KpiService
{
    public const TTL = 30;

    public function __construct(private ReportService $reports) {}

    private function remember(string $k, \Closure $fn)
    {
        return app()->runningUnitTests() ? $fn() : Cache::remember("kpi:$k", self::TTL, $fn);
    }

    public function netSales(string $from, string $to): string
    {
        $g = $this->reports->salesGrouped('customer', $from, $to);

        return Money::s(array_reduce($g, fn ($c, $v) => $c->plus($v['net']), BigDecimal::zero()));
    }

    public function sales(): array
    {
        return $this->remember('sales', fn () => [
            'today' => $this->netSales(now()->toDateString(), now()->toDateString()),
            'mtd' => $this->netSales(now()->startOfMonth()->toDateString(), now()->toDateString()),
        ]);
    }

    public function openOrders(): array
    {
        return $this->remember('open', function () {
            $os = DB::table('sales_orders')->whereIn('status', [S::Confirmed->value, S::Picked->value, S::Delivered->value])->get(['total']);

            return ['count' => $os->count(), 'value' => Money::add(...($os->pluck('total')->all() ?: [0]))];
        });
    }

    public function ar(): array
    {
        return $this->remember('ar', function () {
            $t = $this->reports->arAging()['raw_totals'];
            $past = Money::add($t['31-60'], $t['61-90'], $t['90+']);

            return ['total' => $t['total'], 'past_due' => $past, 'buckets' => array_intersect_key($t, array_flip(['0-30', '31-60', '61-90', '90+']))];
        });
    }

    public function exciseMtd(): string
    {
        return $this->remember('excise', function () {
            $m = $this->reports->accountMovement(PostingService::KY_EXCISE_PAYABLE, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfDay());

            return Money::s($m['credit']);
        });
    }

    /** Active, non-archived products with a reorder point whose available qty (on hand - reserved) is at or below it. */
    public function lowStock(): array
    {
        return $this->remember('low', function () {
            $ps = Product::where('active', true)->notArchived()->whereNotNull('reorder_point')->where('reorder_point', '>', 0)->with('inventories')->get();
            $low = $ps->filter(function ($p) {
                $avail = $p->inventories->reduce(fn ($c, $i) => $c->plus(Money::of($i->qty))->minus(Money::of($i->reserved)), BigDecimal::zero());

                return $avail->isLessThanOrEqualTo(Money::of($p->reorder_point));
            });

            return ['count' => $low->count(), 'items' => $low->take(10)->map(fn ($p) => "{$p->sku} {$p->name}")->values()->all()];
        });
    }

    /** Top N by net sales month-to-date: [[label, net], ...] */
    public function top(string $dim, int $n = 5): array
    {
        return $this->remember("top:$dim", function () use ($dim, $n) {
            $r = $this->reports->salesBy($dim, now()->startOfMonth()->toDateString(), now()->toDateString());

            return array_map(fn ($row) => [$row[0], $row[4]], array_slice($r['rows'], 0, $n));
        });
    }

    /** Daily net sales for the last $days days: ['labels'=>[], 'values'=>[]] */
    public function trend(int $days = 30): array
    {
        return $this->remember("trend:$days", function () use ($days) {
            $start = now()->subDays($days - 1)->startOfDay();
            $by = [];
            $rows = DB::table('sales_orders')->where('status', S::Invoiced->value)->where('invoiced_at', '>=', $start)->get(['invoiced_at', 'total']);
            foreach ($rows as $r) {
                $d = substr((string) $r->invoiced_at, 0, 10);
                $by[$d] = Money::add($by[$d] ?? 0, $r->total);
            }
            foreach (DB::table('credit_memos')->where('status', 'posted')->whereDate('memo_date', '>=', $start->toDateString())->get(['memo_date', 'total']) as $m) {
                $d = substr((string) $m->memo_date, 0, 10);
                $by[$d] = Money::s(Money::of($by[$d] ?? 0)->minus(Money::of($m->total)));
            }
            $labels = $values = [];
            for ($i = 0; $i < $days; $i++) {
                $d = $start->copy()->addDays($i)->toDateString();
                $labels[] = substr($d, 5);
                $values[] = (float) ReportService::d2($by[$d] ?? 0); // chart display only
            }

            return compact('labels', 'values');
        });
    }
}
