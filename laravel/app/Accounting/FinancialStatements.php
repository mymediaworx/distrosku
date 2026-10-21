<?php

namespace App\Accounting;

use App\Analytics\ReportService;
use App\Services\PostingService as P;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Trial balance, P&L, balance sheet and cash flow (indirect) straight from the GL, with an optional comparison column.
 * compare: none | prior_period | prior_year. No year-end closing entries are posted: earnings before the current
 * calendar year show as Retained earnings, the current year as Current year earnings.
 */
class FinancialStatements
{
    public const COMPARE = ['none' => 'No comparison', 'prior_period' => 'Prior period', 'prior_year' => 'Same period last year'];

    public function __construct(private Ledger $gl) {}

    private static function d2($v): string
    {
        return ReportService::d2($v);
    }

    /** Comparison range for [from, to]. */
    public static function compareRange(string $from, string $to, string $compare): ?array
    {
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        if ($compare === 'prior_year') {
            return [$f->subYearNoOverflow()->toDateString(), $t->subYearNoOverflow()->toDateString()];
        }
        if ($compare === 'prior_period') {
            if ($f->isSameDay($f->startOfMonth()) && $t->isSameDay($t->endOfMonth())) { // whole months: previous same number of months
                $months = $f->diffInMonths($t->addDay());
                $pf = $f->subMonthsNoOverflow((int) round($months));

                return [$pf->toDateString(), $f->subDay()->toDateString()];
            }
            $days = (int) $f->diffInDays($t) + 1;

            return [$f->subDays($days)->toDateString(), $f->subDay()->toDateString()];
        }

        return null;
    }

    public static function compareAsOf(string $asOf, string $compare): ?string
    {
        $d = CarbonImmutable::parse($asOf);

        return match ($compare) {
            'prior_year' => $d->subYearNoOverflow()->toDateString(),
            'prior_period' => $d->subMonthNoOverflow()->endOfMonth()->toDateString(),
            default => null,
        };
    }

    private function withCompare(array $cols, ?string $label): array
    {
        return $label ? array_merge($cols, [$label, 'Change']) : $cols;
    }

    public function trialBalance(?string $asOf = null, string $compare = 'none'): array
    {
        $asOf = CarbonImmutable::parse($asOf ?: now()->toDateString())->toDateString();
        $cmp = self::compareAsOf($asOf, $compare);
        $cur = $this->gl->net(null, $asOf);
        $old = $cmp ? $this->gl->net(null, $cmp) : [];
        $rows = [];
        $td = $tc = $od = $oc = BigDecimal::zero();
        foreach ($this->gl->accounts() as $a) {
            $v = $cur[$a->code] ?? BigDecimal::zero();
            $o = $old[$a->code] ?? BigDecimal::zero();
            if ($v->isZero() && $o->isZero()) {
                continue;
            }
            $row = [$a->code, $a->name, $a->type, $v->isPositive() ? self::d2($v) : '', $v->isNegative() ? self::d2($v->abs()) : ''];
            $td = $v->isPositive() ? $td->plus($v) : $td;
            $tc = $v->isNegative() ? $tc->plus($v->abs()) : $tc;
            if ($cmp) {
                $row[] = self::d2($o);
                $row[] = self::d2($v->minus($o));
                $od = $o->isPositive() ? $od->plus($o) : $od;
                $oc = $o->isNegative() ? $oc->plus($o->abs()) : $oc;
            }
            $rows[] = $row;
        }
        $cols = ['Code', 'Account', 'Type', 'Debit', 'Credit'];
        $totals = ['', 'Total', '', self::d2($td), self::d2($tc)];
        if ($cmp) {
            $cols = array_merge($cols, ["Net as of $cmp", 'Change']);
            $totals = array_merge($totals, ['', '']);
        }

        return ['key' => 'trial_balance', 'title' => 'Trial balance', 'period' => "As of $asOf".($cmp ? " (compared with $cmp)" : ''),
            'columns' => $cols, 'rows' => $rows, 'totals' => $totals,
            'notes' => [$td->isEqualTo($tc) ? 'Debits equal credits.' : 'OUT OF BALANCE: debits '.self::d2($td).' vs credits '.self::d2($tc).'.',
                'Comparison column shows the net balance (debit positive, credit negative).'],
            'balanced' => $td->isEqualTo($tc)];
    }

    /** P&L figures (credit-natural revenue, debit-natural expense) for a range. */
    private function plFigures(string $from, string $to): array
    {
        $net = $this->gl->net($from, $to);
        $out = ['revenue' => [], 'cogs' => [], 'expense' => []];
        foreach ($this->gl->accounts() as $a) {
            $v = $net[$a->code] ?? BigDecimal::zero();
            if ($a->type === 'revenue') {
                $out['revenue'][$a->code] = [$a->name, $v->negated()];
            } elseif ($a->type === 'expense') {
                $out[str_starts_with($a->code, '5') ? 'cogs' : 'expense'][$a->code] = [$a->name, $v];
            }
        }
        $sum = fn ($g) => array_reduce($out[$g], fn ($c, $x) => $c->plus($x[1]), BigDecimal::zero());
        $out['tot_revenue'] = $sum('revenue');
        $out['tot_cogs'] = $sum('cogs');
        $out['tot_expense'] = $sum('expense');
        $out['gross'] = $out['tot_revenue']->minus($out['tot_cogs']);
        $out['net'] = $out['gross']->minus($out['tot_expense']);

        return $out;
    }

    public function profitLoss(?string $from = null, ?string $to = null, string $compare = 'none'): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();
        $range = self::compareRange($from, $to, $compare);
        $c = $this->plFigures($from, $to);
        $p = $range ? $this->plFigures(...$range) : null;
        $rows = [];
        $line = function (string $label, BigDecimal $v, ?BigDecimal $o, string $code = '') use (&$rows, $p) {
            $r = [$code, $label, self::d2($v)];
            if ($p) {
                $o ??= BigDecimal::zero();
                $r[] = self::d2($o);
                $r[] = self::d2($v->minus($o));
            }
            $rows[] = $r;
        };
        $head = function (string $label) use (&$rows, $p) {
            $rows[] = $p ? ['', strtoupper($label), '', '', ''] : ['', strtoupper($label), ''];
        };
        foreach (['revenue' => 'Revenue', 'cogs' => 'Cost of goods sold', 'expense' => 'Operating expenses'] as $g => $label) {
            $head($label);
            foreach ($c[$g] as $code => [$name, $v]) {
                $o = $p[$g][$code][1] ?? null;
                if ($v->isZero() && (! $o || $o->isZero())) {
                    continue;
                }
                $line($name, $v, $o, $code);
            }
            $line("Total $label", $c["tot_$g"], $p["tot_$g"] ?? null);
            if ($g === 'cogs') {
                $line('Gross profit', $c['gross'], $p['gross'] ?? null);
            }
        }
        $line('Net income', $c['net'], $p['net'] ?? null);
        $cols = ['Code', 'Line', "$from to $to"];
        if ($p) {
            $cols = array_merge($cols, ["{$range[0]} to {$range[1]}", 'Change']);
        }

        return ['key' => 'profit_loss', 'title' => 'Profit & loss', 'period' => "$from to $to", 'columns' => $cols, 'rows' => $rows, 'totals' => null,
            'notes' => ['Accrual basis from posted journals. 5xxx accounts are cost of goods sold; other expense accounts are operating expenses.'],
            'net_income' => (string) $c['net']->toScale(4)];
    }

    private function bsFigures(string $asOf): array
    {
        $net = $this->gl->net(null, $asOf);
        $yearStart = CarbonImmutable::parse($asOf)->startOfYear();
        $cy = $this->gl->net($yearStart->toDateString(), $asOf);
        $out = ['asset' => [], 'liability' => [], 'equity' => []];
        $earningsAll = BigDecimal::zero();
        $earningsCy = BigDecimal::zero();
        foreach ($this->gl->accounts() as $a) {
            $v = $net[$a->code] ?? BigDecimal::zero();
            if (in_array($a->type, ['revenue', 'expense'], true)) {
                $earningsAll = $earningsAll->minus($v);
                $earningsCy = $earningsCy->minus($cy[$a->code] ?? BigDecimal::zero());

                continue;
            }
            $out[$a->type][$a->code] = [$a->name, Ledger::creditNatural($a->type) ? $v->negated() : $v];
        }
        $re = $out['equity'][P::RETAINED_EARNINGS][1] ?? BigDecimal::zero();
        $out['equity'][P::RETAINED_EARNINGS] = ['Retained Earnings', $re->plus($earningsAll->minus($earningsCy))];
        $out['equity']['CYE'] = ['Current year earnings', $earningsCy];
        foreach (['asset', 'liability', 'equity'] as $g) {
            $out["tot_$g"] = array_reduce($out[$g], fn ($c, $x) => $c->plus($x[1]), BigDecimal::zero());
        }

        return $out;
    }

    public function balanceSheet(?string $asOf = null, string $compare = 'none'): array
    {
        $asOf = CarbonImmutable::parse($asOf ?: now()->toDateString())->toDateString();
        $cmp = self::compareAsOf($asOf, $compare);
        $c = $this->bsFigures($asOf);
        $p = $cmp ? $this->bsFigures($cmp) : null;
        $rows = [];
        $line = function (string $code, string $label, BigDecimal $v, ?BigDecimal $o) use (&$rows, $p) {
            $r = [$code, $label, self::d2($v)];
            if ($p) {
                $o ??= BigDecimal::zero();
                array_push($r, self::d2($o), self::d2($v->minus($o)));
            }
            $rows[] = $r;
        };
        foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $g => $label) {
            $rows[] = $p ? ['', strtoupper($label), '', '', ''] : ['', strtoupper($label), ''];
            foreach ($c[$g] as $code => [$name, $v]) {
                $o = $p[$g][$code][1] ?? null;
                if ($v->isZero() && (! $o || $o->isZero())) {
                    continue;
                }
                $line($code === 'CYE' ? '' : (string) $code, $name, $v, $o);
            }
            $line('', "Total $label", $c["tot_$g"], $p["tot_$g"] ?? null);
        }
        $le = $c['tot_liability']->plus($c['tot_equity']);
        $line('', 'Total liabilities + equity', $le, $p ? $p['tot_liability']->plus($p['tot_equity']) : null);
        $cols = ['Code', 'Line', "As of $asOf"];
        if ($p) {
            $cols = array_merge($cols, ["As of $cmp", 'Change']);
        }
        $ok = $c['tot_asset']->isEqualTo($le);

        return ['key' => 'balance_sheet', 'title' => 'Balance sheet', 'period' => "As of $asOf", 'columns' => $cols, 'rows' => $rows, 'totals' => null,
            'notes' => [$ok ? 'Assets equal liabilities plus equity.' : 'OUT OF BALANCE: assets '.self::d2($c['tot_asset']).' vs liabilities + equity '.self::d2($le).'.'],
            'balanced' => $ok];
    }

    private function cfFigures(string $from, string $to): array
    {
        $cash = Ledger::cashCodes();
        $net = $this->gl->net($from, $to);
        $ni = BigDecimal::zero();
        $op = $inv = $fin = [];
        foreach ($this->gl->accounts() as $a) {
            $v = $net[$a->code] ?? BigDecimal::zero();
            if (in_array($a->type, ['revenue', 'expense'], true)) {
                $ni = $ni->minus($v);

                continue;
            }
            if (in_array($a->code, $cash, true) || $v->isZero()) {
                continue;
            }
            $effect = $v->negated(); // asset increase (debit) uses cash; liability/equity increase (credit) provides it
            $label = ($v->isPositive() ? 'Increase' : 'Decrease')." in {$a->name}";
            if ($a->type === 'equity') {
                $fin[$a->code] = [$label, $effect];
            } elseif ($a->type === 'asset' && (int) $a->code >= 1500) {
                $inv[$a->code] = [$label, $effect];
            } else {
                $op[$a->code] = [$label, $effect];
            }
        }
        $sum = fn ($g) => array_reduce($g, fn ($c, $x) => $c->plus($x[1]), BigDecimal::zero());
        $opening = BigDecimal::zero();
        $prior = $this->gl->net(null, CarbonImmutable::parse($from)->subDay()->toDateString());
        $all = $this->gl->net(null, $to);
        $closing = BigDecimal::zero();
        foreach ($cash as $code) {
            $opening = $opening->plus($prior[$code] ?? BigDecimal::zero());
            $closing = $closing->plus($all[$code] ?? BigDecimal::zero());
        }
        $opTot = $ni->plus($sum($op));

        return compact('ni', 'op', 'inv', 'fin', 'opening', 'closing') + ['tot_op' => $opTot, 'tot_inv' => $sum($inv), 'tot_fin' => $sum($fin),
            'change' => $opTot->plus($sum($inv))->plus($sum($fin))];
    }

    public function cashFlow(?string $from = null, ?string $to = null, string $compare = 'none'): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();
        $range = self::compareRange($from, $to, $compare);
        $c = $this->cfFigures($from, $to);
        $p = $range ? $this->cfFigures(...$range) : null;
        $rows = [];
        $line = function (string $label, BigDecimal $v, ?BigDecimal $o) use (&$rows, $p) {
            $r = [$label, self::d2($v)];
            if ($p) {
                $o ??= BigDecimal::zero();
                array_push($r, self::d2($o), self::d2($v->minus($o)));
            }
            $rows[] = $r;
        };
        $head = function ($l) use (&$rows, $p) {
            $rows[] = $p ? [strtoupper($l), '', '', ''] : [strtoupper($l), ''];
        };
        $head('Operating activities');
        $line('Net income', $c['ni'], $p['ni'] ?? null);
        foreach (['op' => 'operating', 'inv' => 'investing', 'fin' => 'financing'] as $g => $label) {
            if ($g !== 'op') {
                $head(ucfirst($label).' activities');
            }
            $codes = array_unique(array_merge(array_keys($c[$g]), array_keys($p[$g] ?? [])));
            sort($codes);
            foreach ($codes as $code) {
                $line($c[$g][$code][0] ?? $p[$g][$code][0], $c[$g][$code][1] ?? BigDecimal::zero(), $p ? ($p[$g][$code][1] ?? BigDecimal::zero()) : null);
            }
            $line('Net cash from '.$label.' activities', $c["tot_$g"], $p["tot_$g"] ?? null);
        }
        $line('Net change in cash', $c['change'], $p['change'] ?? null);
        $line('Cash at beginning', $c['opening'], $p['opening'] ?? null);
        $line('Cash at end', $c['closing'], $p['closing'] ?? null);
        $cols = ['Line', "$from to $to"];
        if ($p) {
            $cols = array_merge($cols, ["{$range[0]} to {$range[1]}", 'Change']);
        }
        $ok = $c['opening']->plus($c['change'])->isEqualTo($c['closing']);

        return ['key' => 'cash_flow', 'title' => 'Cash flow (indirect)', 'period' => "$from to $to", 'columns' => $cols, 'rows' => $rows, 'totals' => null,
            'notes' => ['Cash = accounts '.implode(', ', Ledger::cashCodes()).'. Equity accounts are financing; asset accounts 1500+ are investing; everything else is operating.',
                $ok ? 'Beginning cash plus net change equals ending cash.' : 'Check: beginning cash plus net change does not equal ending cash.'],
            'reconciles' => $ok];
    }
}
