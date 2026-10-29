<?php

namespace App\Warehouse;

use App\Exceptions\WorkflowException;
use App\Inventory\CountService;
use App\Models\CycleCountTask;
use App\Models\Inventory;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\SalesOrderLine;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 0.20.17 cycle count plan. Picks which products to count today (daily) or this week (weekly) and records them as tasks.
 * Prioritised by (1) a past count discrepancy not yet recounted, (2) high-risk categories, (3) ABC class by 90-day sales value,
 * (4) days since the last count against a per-class target (A 30d, B 90d, C 180d; high-risk 14d).
 * Counting itself runs through the existing count path (CountService create/start/scan/submit/approve): this class never moves stock or posts to the GL.
 */
class CycleCountPlanner
{
    public const INTERVAL = ['A' => 30, 'B' => 90, 'C' => 180];

    public const HIGH_RISK_INTERVAL = 14;

    public static function enabled(): bool
    {
        return (string) (Setting::get('cycle_plan_enabled') ?? '1') !== '0';
    }

    public static function frequency(): string
    {
        return Setting::get('cycle_plan_frequency') === 'weekly' ? 'weekly' : 'daily';
    }

    /** ISO weekday 1 (Mon) .. 7 (Sun) used for the weekly run. */
    public static function weekday(): int
    {
        return max(1, min(7, (int) (Setting::get('cycle_plan_weekday') ?: 1)));
    }

    public static function size(): int
    {
        return max(1, min(500, (int) (Setting::get('cycle_plan_size') ?: 15)));
    }

    /** @return string[] tax classes treated as high-risk (comma list in Settings; default cigarettes, snuff/chew, vapor) */
    public static function highRiskClasses(): array
    {
        $raw = Setting::get('cycle_plan_high_risk_classes');
        $list = $raw === null ? ['cigarette_stamped', 'snuff_chew', 'vapor'] : array_filter(array_map('trim', explode(',', (string) $raw)));

        return array_values($list);
    }

    /** @return string[] lower-cased department names treated as high-risk */
    public static function highRiskDepartments(): array
    {
        return array_values(array_filter(array_map(fn ($s) => mb_strtolower(trim($s)), explode(',', (string) Setting::get('cycle_plan_high_risk_departments')))));
    }

    /** Date of the most recent scheduled run on or before $today (today itself when daily). */
    public static function dueDate(?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= CarbonImmutable::today();
        if (self::frequency() === 'daily') {
            return $today;
        }

        return $today->subDays(($today->dayOfWeekIso - self::weekday() + 7) % 7);
    }

    /** Generate on the schedule if not yet done for the current period (lazy: hub open or the scheduled command). Returns tasks created. */
    public function ensureCurrent(?CarbonImmutable $today = null): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $today ??= CarbonImmutable::today();
        $due = self::dueDate($today);
        $last = Setting::get('cycle_plan_last_run');
        if ($last && CarbonImmutable::parse($last)->gte($due)) {
            return 0;
        }

        return $this->generateAll($today);
    }

    public function generateAll(?CarbonImmutable $date = null): int
    {
        $date ??= CarbonImmutable::today();
        $n = 0;
        foreach (Warehouse::orderBy('code')->get() as $wh) {
            $n += $this->generate($wh, $date)->count();
        }
        Setting::put('cycle_plan_last_run', $date->toDateString());

        return $n;
    }

    /** @return Collection<int, CycleCountTask> the tasks created for this warehouse */
    public function generate(Warehouse $wh, ?CarbonImmutable $date = null, ?int $limit = null): Collection
    {
        $date ??= CarbonImmutable::today();
        $existing = CycleCountTask::where('warehouse_id', $wh->id)->whereDate('task_date', $date->toDateString())->get();
        if ($existing->isNotEmpty()) {
            return $existing; // already planned for this date: re-running never adds more
        }
        $limit ??= self::size() * (self::frequency() === 'weekly' ? 5 : 1);
        $ranked = $this->rank($wh, $date)->take($limit);
        $made = collect();
        foreach ($ranked as $r) {
            $made->push(CycleCountTask::firstOrCreate(
                ['warehouse_id' => $wh->id, 'product_id' => $r['product_id'], 'task_date' => $date->toDateString()],
                ['reason' => $r['reason'], 'abc_class' => $r['abc'], 'priority' => $r['score'], 'status' => 'open']
            ));
        }

        return $made;
    }

    /** @return Collection<int, array{product_id:int,score:int,reason:string,abc:string,days:?int}> eligible products, best first */
    public function rank(Warehouse $wh, CarbonImmutable $date): Collection
    {
        $stocked = Inventory::where('warehouse_id', $wh->id)->where('qty', '>', 0)->pluck('product_id')->unique();
        if ($stocked->isEmpty()) {
            return collect();
        }
        $busy = CycleCountTask::where('warehouse_id', $wh->id)->whereIn('status', ['open', 'counting'])->pluck('product_id')->all();
        $frozen = InventoryCountLine::whereHas('inventoryCount', fn ($q) => $q->where('warehouse_id', $wh->id)->whereIn('status', ['counting', 'submitted']))->pluck('product_id')->all();
        $skip = array_flip(array_merge($busy, $frozen));

        $products = \App\Models\Product::with('department')->where('active', true)->whereIn('id', $stocked)->get()->keyBy('id');
        $abc = $this->abcClasses($products->keys(), $date);
        $last = $this->lastCounted($wh, $products->keys());
        $bad = $this->discrepancies($wh, $products->keys(), $date);
        $hiClass = self::highRiskClasses();
        $hiDept = self::highRiskDepartments();

        $out = collect();
        foreach ($products as $id => $p) {
            if (isset($skip[$id])) {
                continue;
            }
            $cls = $abc[$id] ?? 'C';
            $cv = $p->tax_class instanceof \BackedEnum ? $p->tax_class->value : (string) $p->tax_class;
            $risky = in_array($cv, $hiClass, true) || ($hiDept && $p->department && in_array(mb_strtolower($p->department->name), $hiDept, true));
            $days = isset($last[$id]) ? (int) CarbonImmutable::parse($last[$id])->startOfDay()->diffInDays($date->startOfDay()) : null;
            $target = $risky ? min(self::HIGH_RISK_INTERVAL, self::INTERVAL[$cls]) : self::INTERVAL[$cls];
            $overdue = $days === null ? 60 : $days - $target; // never counted = very overdue
            $disc = isset($bad[$id]);
            if (! $disc && $overdue < 0) {
                continue; // not due yet
            }
            $score = ($disc ? 100 : 0) + ($risky ? 40 : 0) + ['A' => 30, 'B' => 15, 'C' => 5][$cls] + min(30, max(0, $overdue));
            $reason = $disc ? 'discrepancy' : ($risky ? 'high_risk' : ($overdue > 0 ? 'not_recent' : 'abc_'.strtolower($cls)));
            $out->push(['product_id' => $id, 'score' => (int) $score, 'reason' => $reason, 'abc' => $cls, 'days' => $days]);
        }

        return $out->sortByDesc('score')->values();
    }

    /** @return array<int,string> product_id => A|B|C from the last 90 days of sales value (80% / 95% cumulative cut-offs). */
    public function abcClasses(Collection $productIds, CarbonImmutable $date): array
    {
        $rows = SalesOrderLine::query()->whereIn('product_id', $productIds)
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', ['delivered', 'invoiced'])->where('delivered_at', '>=', $date->subDays(90)->startOfDay()))
            ->selectRaw('product_id, SUM(line_total) as v')->groupBy('product_id')->orderByDesc('v')->get();
        $total = (float) $rows->sum('v');
        $out = [];
        $cum = 0.0;
        foreach ($rows as $r) {
            $before = $total > 0 ? $cum / $total : 1;
            $out[$r->product_id] = $before < 0.80 ? 'A' : ($before < 0.95 ? 'B' : 'C');
            $cum += (float) $r->v;
        }

        return $out; // no sales = C (default in rank())
    }

    /** @return array<int,string> product_id => last counted_at for counts that were submitted/posted in this warehouse */
    public function lastCounted(Warehouse $wh, Collection $productIds): array
    {
        return InventoryCountLine::query()->whereIn('product_id', $productIds)->whereNotNull('counted_at')
            ->whereHas('inventoryCount', fn ($q) => $q->where('warehouse_id', $wh->id)->whereIn('status', ['submitted', 'posted']))
            ->selectRaw('product_id, MAX(counted_at) as last')->groupBy('product_id')->pluck('last', 'product_id')->all();
    }

    /** Products whose LATEST submitted/posted count in the last 90 days had a variance (so they have not been recounted since). */
    public function discrepancies(Warehouse $wh, Collection $productIds, CarbonImmutable $date): array
    {
        $lines = InventoryCountLine::query()->with('inventoryCount:id,submitted_at,posted_at,status')->whereIn('product_id', $productIds)->whereNotNull('counted')
            ->whereHas('inventoryCount', fn ($q) => $q->where('warehouse_id', $wh->id)->whereIn('status', ['submitted', 'posted'])->where('submitted_at', '>=', $date->subDays(90)->startOfDay()))
            ->get()->groupBy('product_id');
        $out = [];
        foreach ($lines as $pid => $ls) {
            $latest = $ls->groupBy('inventory_count_id')->sortByDesc(fn ($g) => $g->first()->inventoryCount->submitted_at)->first();
            if ($latest->contains(fn ($l) => (float) $l->counted !== (float) $l->expected)) {
                $out[$pid] = true;
            }
        }

        return $out;
    }

    // ---- task actions (hub / admin page) ----

    public function assign(CycleCountTask $t, ?User $to): CycleCountTask
    {
        if (! in_array($t->status, ['open', 'counting'], true)) {
            throw new WorkflowException('Only open tasks can be assigned.');
        }
        $t->update(['assigned_to' => $to?->id]);

        return $t;
    }

    public function skip(CycleCountTask $t, ?string $note = null): CycleCountTask
    {
        if ($t->status !== 'open') {
            throw new WorkflowException('Only open tasks can be skipped.');
        }
        $t->update(['status' => 'skipped', 'note' => $note, 'completed_at' => now()]);

        return $t;
    }

    /**
     * Build ONE ordinary cycle count (existing CountService) from open tasks and start it. The counter then scans it on the existing count screen;
     * submit/approve/post is the unchanged count path. Tasks complete when that count is submitted or posted (see sync()).
     *
     * @param  Collection<int, CycleCountTask>  $tasks open tasks of ONE warehouse
     */
    public function startCount(Collection $tasks, User $by): InventoryCount
    {
        $tasks = $tasks->where('status', 'open')->values();
        if ($tasks->isEmpty()) {
            throw new WorkflowException('No open tasks to count.');
        }
        if ($tasks->pluck('warehouse_id')->unique()->count() > 1) {
            throw new WorkflowException('Start one warehouse at a time.');
        }
        $svc = app(CountService::class);
        $count = $svc->create($tasks->first()->warehouse, 'cycle', true, ['product_ids' => $tasks->pluck('product_id')->all()], $by, 'Cycle count plan '.now()->toDateString());
        $svc->start($count);
        CycleCountTask::whereIn('id', $tasks->pluck('id'))->update(['status' => 'counting', 'inventory_count_id' => $count->id, 'assigned_to' => $by->id]);

        return $count->fresh();
    }

    /** Close the loop with the existing count path: submitted/posted count => task counted; cancelled count => task open again. */
    public function sync(): void
    {
        $open = CycleCountTask::with('inventoryCount')->where('status', 'counting')->get();
        foreach ($open as $t) {
            $c = $t->inventoryCount;
            if (! $c || $c->status === 'cancelled') {
                $t->update(['status' => 'open', 'inventory_count_id' => null]);
            } elseif (in_array($c->status, ['submitted', 'posted'], true)) {
                $t->update(['status' => 'counted', 'completed_at' => $c->submitted_at ?? now(), 'completed_by' => $c->submitted_by ?? null]);
            }
        }
    }

    /** Tasks to show now: open/counting tasks dated today or earlier (carry-over) plus today's finished ones. */
    public function today(?int $warehouseId = null): Collection
    {
        $this->sync();
        $today = CarbonImmutable::today();

        return CycleCountTask::with('product', 'warehouse', 'assignee', 'inventoryCount')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('status', ['open', 'counting'])->whereDate('task_date', '<=', $today))
                ->orWhere(fn ($w) => $w->whereIn('status', ['counted', 'skipped'])->whereDate('completed_at', $today)))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'counting' THEN 1 ELSE 2 END")->orderByDesc('priority')->get();
    }
}
