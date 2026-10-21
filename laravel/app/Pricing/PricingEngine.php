<?php

namespace App\Pricing;

use App\Models\Customer;
use App\Models\PriceOverrideLog;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SalesOrderLine;
use App\Services\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The single price engine for admin sales orders, POS, van and the portal.
 * 0.19.1: every applicable price is computed (special prices, rules, list; promos stack on rules/list) and the LOWEST wins.
 * 1. Base = best matching price rule (customer contract > customer group > all customers; then highest min qty met; then latest start), else list price.
 * 2. Promotions (date-bound, by product/department/customer/group) apply on top of that base price; the lowest result wins.
 */
class PricingEngine
{
    public const SOURCES = ['list' => 'List price', 'dated' => 'Dated price', 'qty_break' => 'Qty break', 'group' => 'Group price',
        'contract' => 'Contract price', 'manual' => 'Manual override', 'override' => 'Customer special price', 'override_all' => 'Special price (all customers)'];

    public static function sourceLabel(?string $source): string
    {
        if (! $source) {
            return '';
        }
        $promo = str_ends_with($source, '+promo');
        $base = $promo ? substr($source, 0, -6) : $source;

        return (self::SOURCES[$base] ?? $base).($promo ? ' + promo' : '');
    }

    /** Tie-break when two sources give the same price (first = preferred). */
    public const PRECEDENCE = ['override', 'override_all', 'contract', 'group', 'qty_break', 'dated', 'list'];

    /**
     * 0.19.1: LOWEST AVAILABLE PRICE WINS. Every applicable price is computed: customer special, all-customers special,
     * each matching price rule (contract / group / qty break / dated), list; promotions stack on rule and list prices
     * (as before) but not on special prices. The lowest is charged; ties go by PRECEDENCE. The winner is the line's source.
     */
    public function quote(Product $product, ?Customer $customer, mixed $qty = 1, CarbonInterface|string|null $date = null): PriceQuote
    {
        $all = $this->candidates($product, $customer, $qty, $date);
        usort($all, function ($x, $y) {
            $c = Money::of($x->price)->compareTo(Money::of($y->price));

            return $c !== 0 ? $c : array_search(self::baseSource($x->source), self::PRECEDENCE, true) <=> array_search(self::baseSource($y->source), self::PRECEDENCE, true);
        });

        return $all[0];
    }

    private static function baseSource(string $s): string
    {
        return str_ends_with($s, '+promo') ? substr($s, 0, -6) : $s;
    }

    /** Every applicable price for the line (each already including any promotion that stacks on it). @return list<PriceQuote> */
    public function candidates(Product $product, ?Customer $customer, mixed $qty = 1, CarbonInterface|string|null $date = null): array
    {
        $date = Carbon::parse($date ?? now())->toDateString();
        $qty = Money::of($qty ?: 1);
        $list = Money::s($product->price ?? 0);
        $groupId = $customer?->customer_group_id;
        $out = [];

        foreach (\App\Models\CustomerPriceOverride::candidates($product, $customer, $date) as $o) { // customer-specific first, newest first
            $out[] = new PriceQuote(Money::s($o->price), $list, $o->scopeLabel(), null, null, $o->id);
        }

        $rules = PriceRule::query()->where('product_id', $product->id)->where('active', true)
            ->where('min_qty', '<=', (string) $qty)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('customer_id')->whereNull('customer_group_id'))
                ->when($customer, fn ($w) => $w->orWhere('customer_id', $customer->id))
                ->when($groupId, fn ($w) => $w->orWhere('customer_group_id', $groupId)))
            ->get();
        $promos = $this->promotions($product, $customer, $date);
        $bases = $rules->map(fn (PriceRule $r) => [Money::s($r->price), $r->scopeLabel(), $r->id])->all();
        $bases[] = [$list, 'list', null];
        foreach ($bases as [$base, $source, $ruleId]) {
            $best = null;
            foreach ($promos as $p) {
                $price = $p->kind === 'amount' ? Money::of($base)->minus($p->value)
                    : Money::of($base)->minus(Money::of($base)->multipliedBy(Money::of($p->value)->dividedBy(100, 8, \Brick\Math\RoundingMode::HALF_UP)));
                $price = $price->isNegative() ? Money::of(0) : $price;
                if ($price->isLessThan($base) && ($best === null || $price->isLessThan($best[0]))) {
                    $best = [$price, $p];
                }
            }
            $out[] = $best
                ? new PriceQuote(Money::s($best[0]->toScale(4, \Brick\Math\RoundingMode::HALF_UP)), $list, $source.'+promo', $ruleId, $best[1]->id)
                : new PriceQuote($base, $list, $source, $ruleId);
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, Promotion> */
    public function promotions(Product $product, ?Customer $customer, string $date)
    {
        return Promotion::query()->where('active', true)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->where(fn ($q) => $q->whereNull('department_id')->when($product->department_id, fn ($w) => $w->orWhere('department_id', $product->department_id)))
            ->where(fn ($q) => $q->whereNull('customer_id')->when($customer, fn ($w) => $w->orWhere('customer_id', $customer->id)))
            ->where(fn ($q) => $q->whereNull('customer_group_id')->when($customer?->customer_group_id, fn ($w) => $w->orWhere('customer_group_id', $customer->customer_group_id)))
            ->get();
    }

    /**
     * Called from SalesOrderLine::saving for draft orders. Lines with no price get the quote; a price that differs
     * from the quote is a manual override (kept, flagged and logged); untouched non-manual lines follow the quote (qty breaks).
     */
    public function applyToLine(SalesOrderLine $line): void
    {
        $so = $line->salesOrder;
        $product = Product::find($line->product_id);
        if (! $so || ! $product) {
            return;
        }
        $isDraft = ($so->status?->value ?? $so->status ?? 'draft') === 'draft';
        if ($line->exists && ! $isDraft) {
            return; // confirmed+ lines keep their price (short picks etc.)
        }
        if ($line->exists && ! $line->isDirty(['product_id', 'qty', 'unit_price'])) {
            return;
        }
        $q = $this->quote($product, $so->customer, $line->qty, $so->order_date);
        $line->list_price = $q->listPrice;
        $line->quoted_price = $q->price;

        $given = $line->unit_price;
        $priceTouched = ! $line->exists || $line->isDirty('unit_price');
        $keepManual = $line->exists && ! $priceTouched && $line->getOriginal('price_overridden') && ! $line->isDirty('product_id');
        if ($keepManual) {
            return;
        }
        if ($given === null || $given === '' || ! $priceTouched || Money::of($given)->isEqualTo(Money::of($q->price))) {
            $this->setQuoted($line, $q);

            return;
        }
        $line->unit_price = Money::s($given);
        $line->price_source = 'manual';
        $line->price_overridden = true;
        $line->price_rule_id = null;
        $line->promotion_id = null;
        $line->price_override_id = null;
        $line->pendingOverrideLog = ['quoted_price' => $q->price, 'quoted_source' => $q->source,
            'old_price' => $line->exists ? $line->getOriginal('unit_price') : null, 'new_price' => Money::s($given),
            'user_id' => auth()->id(), 'channel' => $so->channel ?? 'admin', 'sales_order_id' => $so->id, 'product_id' => $product->id];
    }

    private function setQuoted(SalesOrderLine $line, PriceQuote $q): void
    {
        $line->unit_price = $q->price;
        $line->price_source = $q->source;
        $line->price_rule_id = $q->priceRuleId;
        $line->promotion_id = $q->promotionId;
        $line->price_override_id = $q->overrideId;
        $line->price_overridden = false;
    }

    public static function writeLog(SalesOrderLine $line): void
    {
        if ($line->pendingOverrideLog) {
            PriceOverrideLog::create($line->pendingOverrideLog + ['sales_order_line_id' => $line->id]);
            $line->pendingOverrideLog = null;
        }
    }
}
