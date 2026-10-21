<?php

namespace App\Analytics;

use App\Enums\SalesOrderStatus as S;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\Money;
use App\Services\PostingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 management reports. All money is exact-decimal (brick/math); SQL only selects rows, PHP sums them.
 * Sales are recognised when invoiced (sales_orders.invoiced_at); posted credit memos (memo_date) are returns.
 * Archived products stay in all historical figures; only pickers hide them.
 *
 * Report shape: ['key','title','period','columns'=>[], 'rows'=>[[..]], 'totals'=>[..]|null, 'notes'=>[], 'links'=>[]]
 */
class ReportService
{
    public const SALES_DIMENSIONS = ['customer' => 'Customer', 'product' => 'Product', 'department' => 'Department', 'rep' => 'Sales rep'];

    public function __construct(private CustomerBalanceService $balances) {}

    public static function d2(mixed $v): string
    {
        return (string) Money::of($v)->toScale(2, RoundingMode::HALF_UP);
    }

    private static function range(?string $from, ?string $to): array
    {
        $f = CarbonImmutable::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay();
        $t = CarbonImmutable::parse($to ?: now()->toDateString())->endOfDay();

        return [$f, $t];
    }

    private static function period(CarbonImmutable $f, CarbonImmutable $t): string
    {
        return $f->toDateString().' to '.$t->toDateString();
    }

    /**
     * Signed line facts in range: invoiced sales lines (+) and posted credit-memo lines (-).
     * Each: [customer_id, product_id, department_id, rep_id, qty, sales, cost, returns]
     */
    public function salesFacts(?string $from, ?string $to, array $f = []): array
    {
        [$a, $b] = self::range($from, $to);
        $q = DB::table('sales_order_lines as l')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.status', S::Invoiced->value)->whereBetween('o.invoiced_at', [$a, $b])
            ->select('o.customer_id', 'l.product_id', 'p.department_id', DB::raw('COALESCE(o.sales_rep_id, c.sales_rep_id) as rep_id'), 'l.qty', 'l.line_total', 'l.unit_cost');
        $r = DB::table('credit_memo_lines as l')->join('credit_memos as m', 'm.id', '=', 'l.credit_memo_id')->join('sales_orders as o', 'o.id', '=', 'm.sales_order_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')->leftJoin('customers as c', 'c.id', '=', 'm.customer_id')
            ->where('m.status', 'posted')->whereDate('m.memo_date', '>=', $a->toDateString())->whereDate('m.memo_date', '<=', $b->toDateString())
            ->select('m.customer_id', 'l.product_id', 'p.department_id', DB::raw('COALESCE(o.sales_rep_id, c.sales_rep_id) as rep_id'), 'l.qty', 'l.line_total', 'l.unit_cost');
        foreach ([$q, $r] as $x) {
            $x->when($f['customer_id'] ?? null, fn ($w, $v) => $w->where($x === $q ? 'o.customer_id' : 'm.customer_id', $v))
                ->when($f['department_id'] ?? null, fn ($w, $v) => $w->where('p.department_id', $v))
                ->when($f['product_id'] ?? null, fn ($w, $v) => $w->where('l.product_id', $v))
                ->when($f['rep_id'] ?? null, fn ($w, $v) => $w->whereRaw('COALESCE(o.sales_rep_id, c.sales_rep_id) = ?', [$v]));
        }
        $out = [];
        foreach ($q->get() as $l) {
            $out[] = [$l->customer_id, $l->product_id, $l->department_id, $l->rep_id, Money::of($l->qty), Money::of($l->line_total), Money::of(Money::mul($l->qty, $l->unit_cost)), BigDecimal::zero()];
        }
        foreach ($r->get() as $l) {
            $out[] = [$l->customer_id, $l->product_id, $l->department_id, $l->rep_id, Money::of($l->qty)->negated(), BigDecimal::zero(),
                Money::of(Money::mul($l->qty, $l->unit_cost))->negated(), Money::of($l->line_total)];
        }

        return $out;
    }

    /** Grouped totals: key => [qty, sales, returns, net, cost, margin]. */
    public function salesGrouped(string $dim, ?string $from, ?string $to, array $f = []): array
    {
        $idx = ['customer' => 0, 'product' => 1, 'department' => 2, 'rep' => 3][$dim];
        $g = [];
        foreach ($this->salesFacts($from, $to, $f) as $x) {
            $k = $x[$idx] ?? 0;
            $g[$k] ??= ['qty' => BigDecimal::zero(), 'sales' => BigDecimal::zero(), 'returns' => BigDecimal::zero(), 'cost' => BigDecimal::zero()];
            $g[$k]['qty'] = $g[$k]['qty']->plus($x[4]);
            $g[$k]['sales'] = $g[$k]['sales']->plus($x[5]);
            $g[$k]['cost'] = $g[$k]['cost']->plus($x[6]);
            $g[$k]['returns'] = $g[$k]['returns']->plus($x[7]);
        }
        foreach ($g as &$v) {
            $v['net'] = $v['sales']->minus($v['returns']);
            $v['margin'] = $v['net']->minus($v['cost']);
        }

        return $g;
    }

    private function labels(string $dim, array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return match ($dim) {
            'customer' => Customer::whereIn('id', $ids)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} {$c->name}"])->all(),
            'product' => Product::whereIn('id', $ids)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}".($p->archived_at ? ' (archived)' : '')])->all(),
            'department' => Department::whereIn('id', $ids)->pluck('name', 'id')->all(),
            'rep' => User::whereIn('id', $ids)->pluck('name', 'id')->all(),
        };
    }

    public static function pct(BigDecimal $num, BigDecimal $den): string
    {
        return $den->isZero() ? '' : (string) $num->multipliedBy(100)->dividedBy($den, 1, RoundingMode::HALF_UP).'%';
    }

    public function salesBy(string $dim, ?string $from = null, ?string $to = null, array $f = []): array
    {
        [$a, $b] = self::range($from, $to);
        $g = $this->salesGrouped($dim, $from, $to, $f);
        uasort($g, fn ($x, $y) => $y['net']->compareTo($x['net']));
        $names = $this->labels($dim, array_keys($g));
        $none = ['customer' => '(none)', 'product' => '(none)', 'department' => '(no department)', 'rep' => '(no rep)'][$dim];
        $rows = [];
        $t = ['qty' => BigDecimal::zero(), 'sales' => BigDecimal::zero(), 'returns' => BigDecimal::zero(), 'net' => BigDecimal::zero(), 'cost' => BigDecimal::zero(), 'margin' => BigDecimal::zero()];
        foreach ($g as $k => $v) {
            $rows[] = [$names[$k] ?? $none, (string) $v['qty']->stripTrailingZeros(), self::d2($v['sales']), self::d2($v['returns']), self::d2($v['net']), self::d2($v['cost']), self::d2($v['margin']), self::pct($v['margin'], $v['net'])];
            foreach ($t as $kk => $_) {
                $t[$kk] = $t[$kk]->plus($v[$kk]);
            }
        }

        return [
            'key' => "sales_by_$dim", 'title' => 'Sales by '.strtolower(self::SALES_DIMENSIONS[$dim]), 'period' => self::period($a, $b),
            'columns' => [self::SALES_DIMENSIONS[$dim], 'Qty', 'Gross sales', 'Returns', 'Net sales', 'Cost', 'Gross margin', 'Margin %'],
            'rows' => $rows,
            'totals' => ['Total', (string) $t['qty']->stripTrailingZeros(), self::d2($t['sales']), self::d2($t['returns']), self::d2($t['net']), self::d2($t['cost']), self::d2($t['margin']), self::pct($t['margin'], $t['net'])],
            'notes' => ['Sales = invoiced orders by invoice date. Returns = posted credit memos by memo date. Excise is not included in sales.'],
        ];
    }

    public function grossMargin(?string $from = null, ?string $to = null, array $f = []): array
    {
        $r = $this->salesBy($f['group'] ?? 'product', $from, $to, $f);
        $r['key'] = 'gross_margin';
        $r['title'] = 'Gross margin by '.strtolower(self::SALES_DIMENSIONS[$f['group'] ?? 'product']);
        $r['notes'][] = 'Cost = unit cost captured on the order line (credit memos use the returned line cost).';

        return $r;
    }

    /** AR aging per customer as of a date, including remaining beginning balances. */
    public function arAging(?string $asOf = null, array $f = []): array
    {
        $asOf = CarbonImmutable::parse($asOf ?: now()->toDateString())->toDateString();
        $keys = ['0-30', '31-60', '61-90', '90+', 'total'];
        $t = array_fill_keys($keys, BigDecimal::zero());
        $rows = [];
        $customers = Customer::query()->when($f['customer_id'] ?? null, fn ($q, $v) => $q->whereKey($v))->orderBy('name')->get();
        foreach ($customers as $c) {
            $a = $this->balances->aging($c, $asOf);
            if (Money::of($a['total'])->isZero() && Money::of($a['0-30'])->isZero()) {
                continue;
            }
            $rows[] = array_merge(["{$c->code} {$c->name}", $c->terms], array_map(fn ($k) => self::d2($a[$k]), $keys));
            foreach ($keys as $k) {
                $t[$k] = $t[$k]->plus(Money::of($a[$k]));
            }
        }

        return [
            'key' => 'ar_aging', 'title' => 'AR aging', 'period' => "As of $asOf",
            'columns' => ['Customer', 'Terms', 'Current (0-30)', '31-60', '61-90', '90+', 'Total'],
            'rows' => $rows, 'totals' => array_merge(['Total', ''], array_map(fn ($k) => self::d2($t[$k]), $keys)),
            'notes' => ['Open invoices age from the invoice date; the remaining beginning balance ages from its beginning-balance date.'],
            'raw_totals' => array_map(fn ($v) => Money::s($v), $t),
        ];
    }

    /** customer_id resolver for journal sources that touch AR. */
    private function sourceCustomer(?string $type, $id): ?int
    {
        if (! $type || ! $id) {
            return null;
        }
        $cls = Relation::getMorphedModel($type) ?? $type;

        $v = match ($cls) {
            Customer::class => (int) $id,
            SalesOrder::class => SalesOrder::whereKey($id)->value('customer_id'),
            Payment::class => Payment::whereKey($id)->value('customer_id'),
            CreditMemo::class => CreditMemo::whereKey($id)->value('customer_id'),
            default => null,
        };

        return $v === null ? null : (int) $v;
    }

    /**
     * Customer statement straight from the AR (1100) ledger: opening balance before `from`, every AR movement in range
     * (beginning balance, invoices, credit memos, payments, refunds, reversals) with a running balance.
     */
    public function statement(int $customerId, ?string $from = null, ?string $to = null): array
    {
        [$a, $b] = self::range($from, $to);
        $c = Customer::findOrFail($customerId);
        $lines = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', PostingService::AR)->whereDate('j.entry_date', '<=', $b->toDateString())
            ->whereNotNull('j.source_type')
            ->orderBy('j.entry_date')->orderBy('j.id')
            ->get(['j.id', 'j.entry_date', 'j.memo', 'j.source_type', 'j.source_id', 'l.debit', 'l.credit']);
        $open = BigDecimal::zero();
        $run = null;
        $rows = [];
        $dr = BigDecimal::zero();
        $cr = BigDecimal::zero();
        $cache = [];
        foreach ($lines as $l) {
            $ck = $l->source_type.'#'.$l->source_id;
            $cid = $cache[$ck] ??= $this->sourceCustomer($l->source_type, $l->source_id);
            if ($cid !== $c->id) {
                continue;
            }
            $amt = Money::of($l->debit)->minus(Money::of($l->credit));
            $date = substr((string) $l->entry_date, 0, 10);
            if ($date < $a->toDateString()) {
                $open = $open->plus($amt);

                continue;
            }
            $run = ($run ?? $open)->plus($amt);
            $dr = $dr->plus(Money::of($l->debit));
            $cr = $cr->plus(Money::of($l->credit));
            $rows[] = [$date, $l->memo, Money::of($l->debit)->isZero() ? '' : self::d2($l->debit), Money::of($l->credit)->isZero() ? '' : self::d2($l->credit), self::d2($run)];
        }
        $close = $run ?? $open;
        array_unshift($rows, [$a->toDateString(), 'Opening balance', '', '', self::d2($open)]);

        return [
            'key' => 'statement', 'title' => "Customer statement: {$c->code} {$c->name}", 'period' => self::period($a, $b),
            'columns' => ['Date', 'Description', 'Charges', 'Credits', 'Balance'],
            'rows' => $rows, 'totals' => ['', 'Closing balance', self::d2($dr), self::d2($cr), self::d2($close)],
            'notes' => ['From the AR ledger (account 1100), so the beginning balance, invoices, credit memos, payments and refunds all count.'],
            'opening' => Money::s($open), 'closing' => Money::s($close),
        ];
    }

    /** On-hand valuation at current cost. Archived products are kept while they still hold stock. */
    public function inventoryValuation(array $f = []): array
    {
        $q = DB::table('inventories as i')->join('products as p', 'p.id', '=', 'i.product_id')->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
            ->when($f['department_id'] ?? null, fn ($w, $v) => $w->where('p.department_id', $v))
            ->when($f['product_id'] ?? null, fn ($w, $v) => $w->where('p.id', $v))
            ->orderBy('p.sku')->get(['p.id', 'p.sku', 'p.name', 'p.archived_at', 'p.cost', 'p.price', 'd.name as dept', 'i.qty', 'i.reserved']);
        $g = [];
        foreach ($q as $r) {
            $g[$r->id] ??= ['r' => $r, 'qty' => BigDecimal::zero(), 'res' => BigDecimal::zero()];
            $g[$r->id]['qty'] = $g[$r->id]['qty']->plus(Money::of($r->qty));
            $g[$r->id]['res'] = $g[$r->id]['res']->plus(Money::of($r->reserved));
        }
        $rows = [];
        $tc = BigDecimal::zero();
        $tr = BigDecimal::zero();
        $tq = BigDecimal::zero();
        foreach ($g as $x) {
            if ($x['qty']->isZero()) {
                continue;
            }
            $r = $x['r'];
            $cv = Money::of(Money::mul($x['qty'], $r->cost));
            $rv = Money::of(Money::mul($x['qty'], $r->price));
            $tc = $tc->plus($cv);
            $tr = $tr->plus($rv);
            $tq = $tq->plus($x['qty']);
            $rows[] = [$r->sku, $r->name.($r->archived_at ? ' (archived)' : ''), $r->dept ?? '', (string) $x['qty']->stripTrailingZeros(), (string) $x['res']->stripTrailingZeros(), self::d2($r->cost), self::d2($cv), self::d2($rv)];
        }

        return [
            'key' => 'inventory_valuation', 'title' => 'Inventory valuation', 'period' => 'As of '.now()->toDateString(),
            'columns' => ['SKU', 'Product', 'Department', 'On hand', 'Reserved', 'Unit cost', 'Value at cost', 'Value at price'],
            'rows' => $rows, 'totals' => ['Total', '', '', (string) $tq->stripTrailingZeros(), '', '', self::d2($tc), self::d2($tr)],
            'notes' => ['Valued at the product\'s current cost. Includes every warehouse.'], 'raw_total' => Money::s($tc),
        ];
    }

    public function inventoryMovement(?string $from = null, ?string $to = null, array $f = []): array
    {
        [$a, $b] = self::range($from, $to);
        $q = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->whereBetween('m.created_at', [$a, $b])
            ->when($f['department_id'] ?? null, fn ($w, $v) => $w->where('p.department_id', $v))
            ->when($f['product_id'] ?? null, fn ($w, $v) => $w->where('p.id', $v))
            ->orderBy('p.sku')->get(['p.id', 'p.sku', 'p.name', 'p.archived_at', 'm.type', 'm.qty', 'm.unit_cost']);
        $types = [];
        $g = [];
        foreach ($q as $m) {
            $types[$m->type] = true;
            $g[$m->id] ??= ['r' => $m, 'in' => BigDecimal::zero(), 'out' => BigDecimal::zero(), 'by' => []];
            $qty = Money::of($m->qty);
            $qty->isPositive() ? $g[$m->id]['in'] = $g[$m->id]['in']->plus($qty) : $g[$m->id]['out'] = $g[$m->id]['out']->plus($qty->abs());
            $g[$m->id]['by'][$m->type] = ($g[$m->id]['by'][$m->type] ?? BigDecimal::zero())->plus($qty);
        }
        $types = array_keys($types);
        sort($types);
        $rows = [];
        $ti = BigDecimal::zero();
        $to_ = BigDecimal::zero();
        foreach ($g as $x) {
            $r = $x['r'];
            $ti = $ti->plus($x['in']);
            $to_ = $to_->plus($x['out']);
            $rows[] = array_merge([$r->sku, $r->name.($r->archived_at ? ' (archived)' : '')],
                array_map(fn ($t) => isset($x['by'][$t]) ? (string) $x['by'][$t]->stripTrailingZeros() : '', $types),
                [(string) $x['in']->stripTrailingZeros(), (string) $x['out']->stripTrailingZeros(), (string) $x['in']->minus($x['out'])->stripTrailingZeros()]);
        }

        return [
            'key' => 'inventory_movement', 'title' => 'Inventory movement', 'period' => self::period($a, $b),
            'columns' => array_merge(['SKU', 'Product'], array_map(fn ($t) => ucwords(str_replace('_', ' ', $t)), $types), ['In', 'Out', 'Net']),
            'rows' => $rows, 'totals' => array_merge(['Total', ''], array_fill(0, count($types), ''), [(string) $ti->stripTrailingZeros(), (string) $to_->stripTrailingZeros(), (string) $ti->minus($to_)->stripTrailingZeros()]),
            'notes' => ['Signed stock movements by type (receipts, sales, adjustments, transfers, returns).'],
        ];
    }

    /** KY excise accrued (credits to 2100) and paid (debits to 2100) in range, plus saved filings. Links to the KY report pages. */
    public function taxSummary(?string $from = null, ?string $to = null): array
    {
        [$a, $b] = self::range($from, $to);
        $acc = $this->accountMovement(PostingService::KY_EXCISE_PAYABLE, $a, $b);
        $sales = $this->salesGrouped('department', $from, $to);
        $net = array_reduce($sales, fn ($c, $v) => $c->plus($v['net']), BigDecimal::zero());
        $rows = [
            ['KY excise accrued (account 2100 credits)', self::d2($acc['credit'])],
            ['KY excise paid / reversed (account 2100 debits)', self::d2($acc['debit'])],
            ['Net change in KY excise payable', self::d2($acc['credit']->minus($acc['debit']))],
            ['Net sales in period (excise not included)', self::d2($net)],
        ];
        foreach (DB::table('tax_filings')->whereDate('period_start', '>=', $a->toDateString())->whereDate('period_start', '<=', $b->toDateString())->orderBy('period_start')->get() as $tf) {
            $rows[] = ["Filing: {$tf->report_type} ".substr((string) $tf->period_start, 0, 10)." ({$tf->status})", self::d2($tf->tax_due ?? 0)];
        }

        return [
            'key' => 'tax_summary', 'title' => 'Tax & excise summary', 'period' => self::period($a, $b),
            'columns' => ['Item', 'Amount'], 'rows' => $rows, 'totals' => null,
            'notes' => ['The KY filing reports (73A422 tobacco distributor and subjobber cigarette) with XML/CSV/PDF live under Tax Reports; this page only summarises the ledger.'],
            'links' => ['ky_tobacco_distributor', 'ky_subjobber_cigarette'],
            'raw' => ['accrued' => Money::s($acc['credit']), 'paid' => Money::s($acc['debit'])],
        ];
    }

    public function accountMovement(string $code, CarbonImmutable $a, CarbonImmutable $b): array
    {
        $ls = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->whereDate('j.entry_date', '>=', $a->toDateString())->whereDate('j.entry_date', '<=', $b->toDateString())->get(['l.debit', 'l.credit']);

        return ['debit' => $ls->reduce(fn ($c, $l) => $c->plus(Money::of($l->debit)), BigDecimal::zero()),
            'credit' => $ls->reduce(fn ($c, $l) => $c->plus(Money::of($l->credit)), BigDecimal::zero())];
    }

    public function paymentsReceived(?string $from = null, ?string $to = null, array $f = []): array
    {
        [$a, $b] = self::range($from, $to);
        $ps = Payment::with('customer', 'salesOrder')->where('status', 'posted')->whereDate('paid_on', '>=', $a->toDateString())->whereDate('paid_on', '<=', $b->toDateString())
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderBy('paid_on')->orderBy('id')->get();
        $rows = [];
        $by = [];
        $t = BigDecimal::zero();
        foreach ($ps as $p) {
            $amt = Money::of($p->amount);
            $signed = ($p->kind ?? 'payment') === 'refund' ? $amt->negated() : $amt;
            $t = $t->plus($signed);
            $by[$p->method] = ($by[$p->method] ?? BigDecimal::zero())->plus($signed);
            $rows[] = [$p->paid_on->toDateString(), "{$p->customer?->code} {$p->customer?->name}", ucfirst($p->method), ucfirst($p->kind ?? 'payment'),
                $p->salesOrder?->number ?? ($p->applies_to_opening ? 'Beginning balance' : 'On account'), (string) $p->reference, self::d2($signed)];
        }
        ksort($by);
        $notes = ['Posted payments by payment date; refunds are negative. Voided payments are excluded.'];
        foreach ($by as $m => $v) {
            $notes[] = ucfirst($m).': $'.self::d2($v);
        }

        return [
            'key' => 'payments_received', 'title' => 'Payments received', 'period' => self::period($a, $b),
            'columns' => ['Date', 'Customer', 'Method', 'Kind', 'Applied to', 'Reference', 'Amount'],
            'rows' => $rows, 'totals' => ['Total', '', '', '', '', '', self::d2($t)], 'notes' => $notes, 'raw_total' => Money::s($t),
        ];
    }

    public static function toCsv(array $r): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, [$r['title']]);
        fputcsv($h, [$r['period']]);
        fputcsv($h, []);
        fputcsv($h, $r['columns']);
        foreach ($r['rows'] as $row) {
            fputcsv($h, $row);
        }
        if ($r['totals']) {
            fputcsv($h, $r['totals']);
        }
        foreach ($r['notes'] ?? [] as $n) {
            fputcsv($h, [$n]);
        }
        rewind($h);

        return stream_get_contents($h);
    }

    public static function toPdf(array $r): string
    {
        return Pdf::loadView('reports.management-pdf', ['r' => $r, 'company' => Company::current()->name ?: config('erp.company')])
            ->setPaper('letter', count($r['columns']) > 6 ? 'landscape' : 'portrait')->output();
    }
}
