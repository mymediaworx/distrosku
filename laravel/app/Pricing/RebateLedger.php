<?php

namespace App\Pricing;

use App\Models\Buydown;
use App\Models\BuydownAccrual;
use App\Models\CreditMemo;
use App\Models\SalesOrder;
use App\Services\Money;
use App\Services\PostingService;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buydown / manufacturer rebate accounting (0.14.1).
 *  Earned (invoice):        Dr 1250 Manufacturer Rebates Receivable / Cr 5050 Manufacturer Rebates & Buydowns (contra-COGS)
 *  Returned (credit memo):  Dr 5050 / Cr 1250, proportional to the returned quantity of the line
 *  Paid by manufacturer:    Dr 1000 Cash (amount received) / Cr 1250 (net claimed); any short or over payment goes to 5050
 */
class RebateLedger
{
    public const RECEIVABLE = '1250';

    public const CONTRA_COGS = '5050';

    public function __construct(private PostingService $posting) {}

    public function accrue(SalesOrder $so): void
    {
        $so->loadMissing('lines');
        $new = [];
        foreach ($so->lines as $l) {
            $programs = Buydown::query()->where('product_id', $l->product_id)->where('status', 'active')
                ->whereDate('starts_on', '<=', $so->order_date)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $so->order_date))
                ->lockForUpdate()->get();
            foreach ($programs as $b) {
                $key = "a:{$b->id}:{$l->id}";
                if (BuydownAccrual::where('dedupe_key', $key)->exists()) {
                    continue;
                }
                $qty = Money::of($l->qty);
                if ($b->max_units !== null) {
                    $left = Money::of($b->max_units)->minus(Money::of($b->units_sold));
                    if (! $left->isPositive()) {
                        continue;
                    }
                    $qty = $qty->isGreaterThan($left) ? $left : $qty;
                }
                $new[] = BuydownAccrual::create([
                    'buydown_id' => $b->id, 'kind' => 'accrual', 'dedupe_key' => $key, 'sales_order_id' => $so->id, 'sales_order_line_id' => $l->id,
                    'customer_id' => $so->customer_id, 'product_id' => $l->product_id, 'sold_on' => $so->order_date,
                    'qty' => Money::s($qty), 'amount_per_unit' => Money::s($b->amount_per_unit), 'amount' => Money::mul($qty, $b->amount_per_unit),
                ]);
                $b->update(['units_sold' => Money::add($b->units_sold, $qty)]);
            }
        }
        $this->postRows($new, now()->toDateString(), "Rebates/buydowns earned on {$so->number}", $so);
    }

    public function reverseForCreditMemo(CreditMemo $cm): void
    {
        $cm->loadMissing('lines.salesOrderLine');
        $new = [];
        foreach ($cm->lines as $cl) {
            $sol = $cl->salesOrderLine;
            if (! $sol || ! Money::of($sol->qty)->isPositive() || ! Money::of($cl->qty)->isPositive()) {
                continue;
            }
            $ratio = Money::of($cl->qty)->dividedBy(Money::of($sol->qty), 10, RoundingMode::HALF_UP);
            $accruals = BuydownAccrual::where('sales_order_line_id', $sol->id)->where('kind', 'accrual')->get();
            foreach ($accruals as $a) {
                $key = "r:{$a->id}:{$cl->id}";
                if (BuydownAccrual::where('dedupe_key', $key)->exists()) {
                    continue;
                }
                $reversed = Money::of(BuydownAccrual::where('reverses_id', $a->id)->sum('qty'))->negated();
                $left = Money::of($a->qty)->minus($reversed);
                $qty = Money::of($a->qty)->multipliedBy($ratio)->toScale(4, RoundingMode::HALF_UP);
                $qty = $qty->isGreaterThan($left) ? $left : $qty;
                if (! $qty->isPositive()) {
                    continue;
                }
                $new[] = BuydownAccrual::create([
                    'buydown_id' => $a->buydown_id, 'kind' => 'reversal', 'dedupe_key' => $key, 'reverses_id' => $a->id,
                    'credit_memo_id' => $cm->id, 'credit_memo_line_id' => $cl->id, 'sales_order_id' => $a->sales_order_id, 'sales_order_line_id' => $sol->id,
                    'customer_id' => $a->customer_id, 'product_id' => $a->product_id, 'sold_on' => $cm->memo_date->toDateString(),
                    'qty' => Money::s($qty->negated()), 'amount_per_unit' => $a->amount_per_unit, 'amount' => Money::s(Money::of(Money::mul($qty, $a->amount_per_unit))->negated()),
                ]);
                if ($b = Buydown::find($a->buydown_id)) {
                    $n = Money::of($b->units_sold)->minus($qty);
                    $b->update(['units_sold' => Money::s($n->isNegative() ? 0 : $n)]);
                }
            }
        }
        $this->postRows($new, $cm->memo_date->toDateString(), "Rebates/buydowns reversed by credit memo {$cm->number}", $cm);
    }

    /**
     * Manufacturer paid a claim. Rows must be claimed and unpaid (accruals and reversals net together).
     * Dr Cash for the amount received, Cr 1250 for the net claimed; a difference goes to 5050.
     */
    public function recordPayment(iterable $rows, string $date, mixed $received, ?string $reference = null)
    {
        $rows = collect($rows)->filter(fn ($r) => $r->claimed_at && ! $r->paid_at)->values();
        if ($rows->isEmpty()) {
            throw new \App\Exceptions\WorkflowException('Select claimed, unpaid lines (mark them claimed first).');
        }
        $net = Money::of(Money::add(...$rows->pluck('amount')->all()));
        $rec = Money::of($received);
        $diff = $rec->minus($net); // + = overpaid (credit 5050), - = short paid (debit 5050)
        $lines = [
            [PostingService::CASH, $rec->isPositive() ? $rec : 0, $rec->isNegative() ? $rec->negated() : 0, 'Manufacturer rebate payment'],
            [self::RECEIVABLE, $net->isNegative() ? $net->negated() : 0, $net->isPositive() ? $net : 0, 'Clear claimed rebates'],
            [self::CONTRA_COGS, $diff->isNegative() ? $diff->negated() : 0, $diff->isPositive() ? $diff : 0, 'Claim short / over payment'],
        ];

        return DB::transaction(function () use ($rows, $date, $lines, $reference) {
            $j = $this->posting->post($date, 'Manufacturer rebate payment'.($reference ? " {$reference}" : '').' (claims '.$rows->pluck('claim_ref')->unique()->implode(', ').')', $lines);
            BuydownAccrual::whereIn('id', $rows->pluck('id'))->update(['paid_at' => $date, 'payment_journal_id' => $j->id]);

            return $j;
        });
    }

    /** Post journals for rows that have none (accruals made by 0.14.0 before GL posting existed). Returns rows posted. */
    public function postMissing(): int
    {
        $n = 0;
        foreach (BuydownAccrual::whereNull('journal_id')->with('salesOrder')->get()->groupBy(fn ($r) => $r->kind.':'.($r->credit_memo_id ?? $r->sales_order_id)) as $group) {
            $first = $group->first();
            try {
                $source = $first->kind === 'reversal' ? CreditMemo::find($first->credit_memo_id) : $first->salesOrder;
                $this->postRows($group->all(), $first->kind === 'reversal' ? $first->sold_on->toDateString() : ($first->salesOrder?->invoiced_at?->toDateString() ?? now()->toDateString()),
                    'Rebates/buydowns '.($first->kind === 'reversal' ? 'reversed' : 'earned').' (catch-up 0.14.1)', $source);
                $n += $group->count();
            } catch (Throwable $e) {
                Log::warning('Rebate catch-up posting skipped: '.$e->getMessage());
            }
        }

        return $n;
    }

    /** @param array<int, BuydownAccrual> $rows */
    private function postRows(array $rows, string $date, string $memo, $source): void
    {
        if (! $rows) {
            return;
        }
        $sum = Money::of(Money::add(...array_map(fn ($r) => $r->amount, $rows)));
        if ($sum->isZero()) {
            return;
        }
        $abs = $sum->abs();
        $earn = $sum->isPositive();
        $j = $this->posting->post($date, $memo, [
            [self::RECEIVABLE, $earn ? $abs : 0, $earn ? 0 : $abs],
            [self::CONTRA_COGS, $earn ? 0 : $abs, $earn ? $abs : 0],
        ], $source);
        BuydownAccrual::whereIn('id', array_map(fn ($r) => $r->id, $rows))->update(['journal_id' => $j->id]);
    }
}
