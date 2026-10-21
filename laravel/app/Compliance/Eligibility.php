<?php

namespace App\Compliance;

use App\Audit\Audit;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\EligibilityRule;
use App\Models\Product;
use App\Models\ProductCoa;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\VapeDirectoryListing;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 0.18: product eligibility registry. Rules (federal / state / county / city, effective-dated) decide whether a SKU
 * may be sold into a ship-to on a date: hemp/THC limits, synthetic cannabinoids, flavor bans, FDA vape status,
 * state vape directories, current COA, and the state MSA directory (system rule "msa_directory").
 * Blocks stop Confirm (admin, POS, van, portal); warnings are saved on the order. An owner/admin can override with a reason.
 */
class Eligibility
{
    /** @var array<string, Collection> */
    private array $cache = [];

    /** @return Collection<int, EligibilityRule> */
    public function rulesFor(string $state, CarbonInterface|string $date, ?string $county = null, ?string $city = null, bool $includeDisabled = false): Collection
    {
        $d = Carbon::parse($date)->toDateString();
        $k = implode('|', [$state, $d, $county, $city, (int) $includeDisabled]);

        return $this->cache[$k] ??= EligibilityRule::query()
            ->when(! $includeDisabled, fn ($q) => $q->where('enabled', true))
            ->whereDate('effective_from', '<=', $d)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d))
            ->orderBy('id')->get()
            ->filter(fn (EligibilityRule $r) => self::inJurisdiction($r, $state, $county, $city))->values();
    }

    public static function inJurisdiction(EligibilityRule $r, string $state, ?string $county, ?string $city): bool
    {
        if ($r->level === 'federal') {
            return true;
        }
        if ($r->state && strtoupper($r->state) !== strtoupper($state)) {
            return false;
        }
        if ($r->county && strcasecmp(trim($r->county), trim((string) $county)) !== 0) {
            return false;
        }

        return ! ($r->city && strcasecmp(trim($r->city), trim((string) $city)) !== 0);
    }

    public const SELECTORS = ['thc_vape' => 'THC vapes (vape form + hemp/CBD/THC cannabinoid)'];

    public static function selects(EligibilityRule $r, Product $p): bool
    {
        if ($r->selector === 'thc_vape' && ! $p->isThcVape()) {
            return false;
        }
        if ($r->subtype && $r->subtype !== $p->product_subtype) {
            return false;
        }

        return ! ($r->tax_class && $r->tax_class !== $p->tax_class?->value);
    }

    /** Reasons the rule fires for the product (empty = it does not). */
    public function fires(EligibilityRule $r, Product $p, string $state, CarbonInterface|string $date): array
    {
        if (! self::selects($r, $p)) {
            return [];
        }
        $d = Carbon::parse($date)->toDateString();
        $state = strtoupper($state);
        $out = [];
        $hasCond = false;

        foreach ([['thc_total_max_mg', 'thc_total_mg', 'total THC per container'], ['thc_serving_max_mg', 'thc_serving_mg', 'THC per serving']] as [$max, $have, $label]) {
            if ($r->{$max} === null) {
                continue;
            }
            $hasCond = true;
            if ($p->{$have} === null) {
                if ($r->thc_unknown_fires) {
                    $out[] = "{$label} not recorded on the SKU (limit {$this->n($r->{$max})} mg)";
                }
            } elseif (Money::of($p->{$have})->isGreaterThan(Money::of($r->{$max}))) {
                $out[] = "{$label} {$this->n($p->{$have})} mg is over the {$this->n($r->{$max})} mg limit";
            }
        }
        if ($r->synthetic_fires) {
            $hasCond = true;
            if ($p->synthetic_cannabinoid) {
                $out[] = 'synthetic cannabinoid';
            }
        }
        if ($r->flavored_fires) {
            $hasCond = true;
            if ($p->flavored) {
                $out[] = 'flavored product';
            }
        }
        if ($r->fda_statuses) {
            $hasCond = true;
            $st = $p->fda_status ?: 'none';
            if (in_array($st, $r->fda_statuses, true)) {
                $out[] = 'FDA marketing status: '.(Product::FDA_STATUSES[$st] ?? $st);
            }
        }
        if ($r->needs_vape_directory) {
            $hasCond = true;
            if (! VapeDirectoryListing::listed($p, $state, $d)) {
                $out[] = $r->selector === 'thc_vape' && $state === 'KY'
                    ? "not listed on the KY CHFS hemp-derived cannabinoid product registry for {$d}"
                    : "not listed on the {$state} vape directory for {$d}";
            }
        }
        if ($r->needs_current_coa) {
            $hasCond = true;
            if (! ProductCoa::currentFor($p, $d)) {
                $out[] = "no current COA on file for {$d}";
            }
        }
        if ($r->needs_msa_directory) {
            $hasCond = true;
            if ($m = self::msaReason($p, $state, $d)) {
                $out[] = $m;
            }
        }
        if (! $hasCond) {
            $out[] = 'not allowed';
        }

        return $out;
    }

    /** Former ComplianceChecker MSA block, unchanged wording. */
    public static function msaReason(Product $p, string $state, string $d): ?string
    {
        $dirType = PactCategory::directoryType($p);
        if (! $dirType) {
            return null;
        }
        if (MsaDirectory::loaded($state, $d)) {
            if (! MsaDirectory::listed($p, $state, $d)) {
                $brand = MsaDirectory::brandOf($p) ?: '(no brand)';

                return "Brand family {$brand} ({$dirType}) is not on the {$state} MSA directory for {$d}; sale into {$state} is blocked.";
            }
        } elseif (Setting::get('msa_directory_strict')) {
            return "No {$state} MSA directory list is loaded for {$d}; {$dirType} sales into {$state} are blocked until one is imported.";
        }

        return null;
    }

    public function message(EligibilityRule $r, array $why): string
    {
        if ($r->key === 'msa_directory') {
            return implode(' ', $why);
        }
        $cite = $r->source_note && ! $r->verify_with_counsel ? ' ['.mb_strimwidth($r->source_note, 0, 120, '…').']' : '';

        return "{$r->name} ({$r->jurisdictionLabel()}, from {$r->effective_from->toDateString()}): ".implode('; ', $why).$cite.'.';
    }

    /** @return array{block: string[], warn: string[]} */
    public function evaluate(Product $p, ShipTo $shipTo, CarbonInterface|string|null $date = null, bool $includeDisabled = false): array
    {
        $date ??= Carbon::today();
        $out = ['block' => [], 'warn' => []];
        foreach ($this->rulesFor(strtoupper($shipTo->state), $date, $shipTo->county, $shipTo->city, $includeDisabled) as $r) {
            if ($why = $this->fires($r, $p, $shipTo->state, $date)) {
                $out[$r->action === 'warn' ? 'warn' : 'block'][] = $this->message($r, $why);
            }
        }
        $out['block'] = array_values(array_unique($out['block']));
        $out['warn'] = array_values(array_unique($out['warn']));

        return $out;
    }

    /** Dates an order is checked on: sale (order) date and ship date. */
    public static function orderDates(SalesOrder $so): array
    {
        return collect([$so->order_date ?? Carbon::today(), $so->ship_date])->filter()
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all();
    }

    /** @return array{block: array<string, string[]>, warn: array<string, string[]>} keyed by SKU */
    public function checkOrder(SalesOrder $so): array
    {
        $so->loadMissing('lines.product', 'shipTo');
        $out = ['block' => [], 'warn' => []];
        if (! $so->shipTo) {
            return $out;
        }
        foreach ($so->lines as $l) {
            foreach (self::orderDates($so) as $d) {
                $e = $this->evaluate($l->product, $so->shipTo, $d);
                foreach (['block', 'warn'] as $k) {
                    if ($e[$k]) {
                        $out[$k][$l->product->sku] = array_values(array_unique(array_merge($out[$k][$l->product->sku] ?? [], $e[$k])));
                    }
                }
            }
        }

        return $out;
    }

    /** Owner/admin override of eligibility blocks on one draft order (reason required, logged). */
    public function override(SalesOrder $so, User $by, string $reason): SalesOrder
    {
        $reason = trim($reason);
        if (! $by->isAdmin()) {
            Audit::action('eligibility_override_blocked', $so, "Blocked: {$by->name} tried to override eligibility on {$so->number}");
            throw new WorkflowException('Only an owner or admin can override product eligibility.');
        }
        if (mb_strlen($reason) < 3) {
            throw new WorkflowException('Give a reason for the override.');
        }
        if ($so->status !== S::Draft) {
            throw new WorkflowException('Only a draft order can be overridden.');
        }
        $blocks = $this->checkOrder($so)['block'];
        $so->forceFill(['elig_override_by' => $by->id, 'elig_override_at' => now(), 'elig_override_reason' => mb_substr($reason, 0, 500)])->save();
        Audit::action('eligibility_override', $so, "Eligibility override by {$by->name}: {$reason}", ['reason' => $reason, 'blocked' => $blocks]);

        return $so;
    }

    /**
     * Inventory at risk: SKUs with stock that become blocked in the state on a future date within the horizon.
     *
     * @return array<int, array<string, mixed>>
     */
    public function atRisk(string $state, int $horizonDays = 120, bool $includeDisabled = false, int $velocityDays = 30): array
    {
        $state = strtoupper($state);
        $today = Carbon::today();
        $until = $today->copy()->addDays($horizonDays);
        $rules = EligibilityRule::query()->where('action', 'block')
            ->when(! $includeDisabled, fn ($q) => $q->where('enabled', true))
            ->whereDate('effective_from', '>', $today->toDateString())->whereDate('effective_from', '<=', $until->toDateString())
            ->orderBy('effective_from')->get()
            ->filter(fn ($r) => $r->level === 'federal' || ! $r->state || strtoupper($r->state) === $state)->values(); // county/city rules shown for the state
        if ($rules->isEmpty()) {
            return [];
        }
        $onHand = DB::table('inventories')->select('product_id', DB::raw('sum(qty) as q'))->groupBy('product_id')->having(DB::raw('sum(qty)'), '>', 0)->pluck('q', 'product_id');
        $since = $today->copy()->subDays($velocityDays)->toDateString();
        $sold = DB::table('sales_order_lines as l')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')->join('ship_tos as t', 't.id', '=', 'o.ship_to_id')
            ->where('t.state', $state)->whereNotIn('o.status', [S::Draft->value, S::Cancelled->value])->whereDate('o.order_date', '>=', $since)
            ->select('l.product_id', DB::raw('sum(l.qty) as q'))->groupBy('l.product_id')->pluck('q', 'product_id');
        $rows = [];
        $products = Product::whereIn('id', $onHand->keys())->where('active', true)->notArchived()->orderBy('sku')->get();
        foreach ($products as $p) {
            foreach ($rules as $r) {
                $why = $this->fires($r, $p, $state, $r->effective_from);
                if (! $why) {
                    continue;
                }
                $qty = (float) $onHand[$p->id];
                $days = (int) $today->diffInDays($r->effective_from);
                $rate = round(((float) ($sold[$p->id] ?? 0)) / max(1, $velocityDays), 4);
                $sellable = min($qty, $rate * $days);
                $rows[] = ['product' => $p, 'rule' => $r, 'date' => $r->effective_from->toDateString(), 'days_left' => $days, 'on_hand' => $qty,
                    'value' => round($qty * (float) $p->cost, 2), 'sold_per_day' => $rate, 'stranded_qty' => round($qty - $sellable, 4),
                    'stranded_value' => round(($qty - $sellable) * (float) $p->cost, 2), 'why' => implode('; ', $why), 'draft' => ! $r->enabled];
                break; // earliest rule that hits this SKU
            }
        }

        return $rows;
    }

    private function n($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    }
}
