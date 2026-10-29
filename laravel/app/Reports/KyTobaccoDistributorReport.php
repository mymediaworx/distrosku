<?php

namespace App\Reports;

use App\Enums\TaxClass;
use App\Models\CreditMemoLine;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrderLine;
use App\Services\KyExcise;
use App\Services\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * KY Form 73A422 "Monthly Report of Tobacco Products, Snuff, and Chewing Tobacco" (instructions rev. 8-13; e-filed in MyTaxes since 2020).
 * Tax arises in the month the product is SOLD to a KY retailer / unlicensed buyer; snuff and chew by net weight units.
 */
class KyTobaccoDistributorReport extends TaxReport
{
    public function key(): string
    {
        return 'ky_tobacco_distributor';
    }

    public function title(): string
    {
        return 'KY Tobacco Distributor Return (Form 73A422)';
    }

    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $x = app(KyExcise::class);
        $on = $end->toDateString();
        $notes = [];

        $lines = SalesOrderLine::query()->with(['product', 'salesOrder.customer', 'salesOrder.shipTo'])
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', ['delivered', 'invoiced'])
                ->whereBetween('delivered_at', [$start->startOfDay(), $end->endOfDay()])
                ->whereHas('shipTo', fn ($s) => $s->where('state', 'KY')))
            ->whereHas('product', fn ($q) => $q->whereIn('tax_class', ['snuff_chew', 'cigar', 'pipe', 'ryo', 'vapor'])->where('ky_tax_paid_by_supplier', false))
            ->get();

        $otp = Money::of(0);
        $premium = Money::of(0);
        $vaporOpen = Money::of(0);
        $vaporClosedUnits = Money::of(0);
        $snuffUnits = Money::of(0);
        $chew = ['single' => Money::of(0), 'half_pound' => Money::of(0), 'pound' => Money::of(0)];
        $chewPctBase = Money::of(0); // 0.20.17: chew sold on/after the switch date, taxed as % of price
        $over16 = Money::of(0); // tax amount for >16oz units
        $detail = [];
        $refs = []; // drill-down: bucket => [ref ...]
        $ref = function (string $bucket, string $type, $doc, $party, $p, $qty, $amount) use (&$refs) {
            $refs[$bucket][] = ['type' => $type, 'id' => $doc->id, 'number' => $doc->number, 'date' => $type === 'so' ? $doc->delivered_at?->toDateString() : ($type === 'cm' ? $doc->memo_date?->toDateString() : $doc->received_at?->toDateString()),
                'party' => $party, 'sku' => $p?->sku, 'qty' => Money::s($qty), 'amount' => Money::s($amount)];
        };

        foreach ($lines as $l) {
            $p = $l->product;
            $so = $l->salesOrder;
            $units = KyExcise::consumerUnits($p, $l->qty);
            $basis = '';
            $bucket = 'otp';
            $tax = BigDecimal::zero();
            switch (true) {
                case $p->tax_class === TaxClass::SnuffChew && ($p->smokeless_type ?? 'snuff') !== 'snuff' && $x->chewIsPercent($so->delivered_at):
                    $chewPctBase = $chewPctBase->plus(Money::of($l->line_total));
                    $tax = Money::of($l->line_total)->multipliedBy($x->rate(KyExcise::CHEW_PCT, $so->delivered_at));
                    $basis = 'chew % of price';
                    $bucket = 'chew_pct';
                    break;
                case $p->tax_class === TaxClass::SnuffChew && $p->net_weight_oz === null:
                    $notes[$p->sku] = "{$p->sku}: no net weight; excluded from snuff/chew lines. Fix the product.";
                    $basis = 'MISSING WEIGHT';
                    $bucket = 'excluded';
                    break;
                case $p->tax_class === TaxClass::SnuffChew && ($p->smokeless_type ?? 'snuff') === 'snuff':
                    $inc = $units->multipliedBy(KyExcise::snuffIncrements($p->net_weight_oz));
                    $snuffUnits = $snuffUnits->plus($inc);
                    $tax = $inc->multipliedBy($x->rate(KyExcise::SNUFF, $on));
                    $basis = "snuff {$inc} x 1.5oz";
                    $bucket = 'snuff';
                    break;
                case $p->tax_class === TaxClass::SnuffChew:
                    $b = KyExcise::chewBucket($p->net_weight_oz);
                    if ($b === 'over_16') {
                        $tax = $x->smokelessUnitTax($p, $on)->multipliedBy($units);
                        $over16 = $over16->plus($tax);
                    } else {
                        $chew[$b] = $chew[$b]->plus($units);
                        $tax = $x->smokelessUnitTax($p, $on)->multipliedBy($units);
                    }
                    $basis = "chew {$b} x {$units}";
                    $bucket = 'chew_'.$b;
                    break;
                case $p->tax_class === TaxClass::Vapor:
                    // Guess: vapor uses units_per_case as cartridges when it is a closed system; open systems by price.
                    if ($p->category && str_contains(strtolower($p->category), 'open')) {
                        $vaporOpen = $vaporOpen->plus(Money::of($l->line_total));
                        $basis = 'vapor open price';
                    } else {
                        $vaporClosedUnits = $vaporClosedUnits->plus($units);
                        $basis = "vapor closed {$units} cartridges";
                    }
                    $bucket = 'vapor';
                    $notes['vapor'] = 'Vapor products are reported on a separate KY vapor return in MyTaxes; shown here for reference only (guess at closed/open split: category containing "open" = open system).';
                    break;
                case $p->premium_cigar && $start->greaterThanOrEqualTo('2026-07-01'):
                    $premium = $premium->plus(Money::of($l->line_total));
                    $basis = 'premium cigar price';
                    $bucket = 'premium';
                    break;
                default:
                    $otp = $otp->plus(Money::of($l->line_total));
                    $basis = 'OTP price';
            }
            $ref($bucket, 'so', $so, $so->customer->name, $p, $l->qty, $l->line_total);
            $detail[] = [$so->delivered_at?->toDateString(), $so->number, $so->customer->name, $p->sku, $p->name, $p->tax_class->label(),
                $p->net_weight_oz === null ? '' : Money::s($p->net_weight_oz), Money::s($l->qty), (string) $units, Money::s($l->line_total), $basis, Money::s($tax)];
        }

        // Returns (posted credit memos, memo date in period, KY ship-to): reduce the period's taxable base (lines 2/9/18/23/28).
        $adj = ['otp' => Money::of(0), 'snuff' => Money::of(0), 'single' => Money::of(0), 'half_pound' => Money::of(0), 'pound' => Money::of(0), 'over16' => Money::of(0), 'chew_pct' => Money::of(0), 'premium' => Money::of(0)];
        $returns = [];
        $cml = CreditMemoLine::with(['product', 'creditMemo.customer'])
            ->whereHas('creditMemo', fn ($q) => $q->where('status', 'posted')->whereBetween('memo_date', [$start->toDateString(), $end->toDateString()])
                ->whereHas('salesOrder.shipTo', fn ($s) => $s->where('state', 'KY')))
            ->whereHas('product', fn ($q) => $q->whereIn('tax_class', ['snuff_chew', 'cigar', 'pipe', 'ryo'])->where('ky_tax_paid_by_supplier', false))->get();
        foreach ($cml as $l) {
            $p = $l->product;
            $units = KyExcise::consumerUnits($p, $l->qty);
            $chewPct = $p->tax_class === TaxClass::SnuffChew && ($p->smokeless_type ?? 'snuff') !== 'snuff' && $x->chewIsPercent($l->creditMemo->memo_date);
            if (! $chewPct && $p->tax_class === TaxClass::SnuffChew && $p->net_weight_oz === null) {
                continue;
            }
            $cm = $l->creditMemo;
            $bucket = match (true) {
                $p->tax_class === TaxClass::SnuffChew && ($p->smokeless_type ?? 'snuff') === 'snuff' => 'snuff',
                $chewPct => 'chew_pct',
                $p->tax_class === TaxClass::SnuffChew => 'chew_'.KyExcise::chewBucket($p->net_weight_oz),
                $p->premium_cigar && $start->greaterThanOrEqualTo('2026-07-01') => 'premium',
                default => 'otp',
            };
            $ref($bucket.'_ret', 'cm', $cm, $cm->customer->name, $p, $l->qty, $l->line_total);
            if ($p->tax_class === TaxClass::SnuffChew && ($p->smokeless_type ?? 'snuff') === 'snuff') {
                $adj['snuff'] = $adj['snuff']->plus($units->multipliedBy(KyExcise::snuffIncrements($p->net_weight_oz)));
            } elseif ($chewPct) {
                $adj['chew_pct'] = $adj['chew_pct']->plus(Money::of($l->line_total));
            } elseif ($p->tax_class === TaxClass::SnuffChew) {
                $b = KyExcise::chewBucket($p->net_weight_oz);
                $b === 'over_16' ? $adj['over16'] = $adj['over16']->plus($x->smokelessUnitTax($p, $on)->multipliedBy($units)) : $adj[$b] = $adj[$b]->plus($units);
            } elseif ($p->premium_cigar && $start->greaterThanOrEqualTo('2026-07-01')) {
                $adj['premium'] = $adj['premium']->plus(Money::of($l->line_total));
            } else {
                $adj['otp'] = $adj['otp']->plus(Money::of($l->line_total));
            }
            $returns[] = [$l->creditMemo->memo_date->toDateString(), $l->creditMemo->number, $l->creditMemo->customer->name, $p->sku, $p->name,
                Money::s($l->qty), (string) $units, Money::s($l->line_total), $l->disposition];
        }
        $otpNet = $otp->minus($adj['otp']);
        $snuffNet = $snuffUnits->minus($adj['snuff']);
        foreach (['single', 'half_pound', 'pound'] as $b) {
            $chew[$b] = $chew[$b]->minus($adj[$b]);
        }
        $over16 = $over16->minus($adj['over16']);
        $premium = $premium->minus($adj['premium']);

        $r = fn ($t) => $x->rate($t, $on);
        $disc = $r(KyExcise::DISTRIBUTOR_DISCOUNT_PCT);
        $u = fn (BigDecimal $v) => (string) $v->stripTrailingZeros();
        $c2 = fn (BigDecimal $v) => (string) $v->toScale(2, RoundingMode::HALF_UP);

        $l5 = $otpNet->multipliedBy($r(KyExcise::OTP_PCT));
        $l6 = $l5->multipliedBy($disc);
        $l7 = $l5->minus($l6);
        $l12 = $snuffNet->multipliedBy($r(KyExcise::SNUFF));
        $l13 = $l12->multipliedBy($disc);
        $l14 = $l12->minus($l13);
        $cs = $chew['single']->multipliedBy($r(KyExcise::CHEW_SINGLE));
        $ch = $chew['half_pound']->multipliedBy($r(KyExcise::CHEW_HALF_POUND));
        $cp = $chew['pound']->multipliedBy($r(KyExcise::CHEW_POUND));
        $chewPctNet = $chewPctBase->minus($adj['chew_pct']);
        $chewPctRate = $chewPctBase->isZero() && $adj['chew_pct']->isZero() && ! $x->chewIsPercent($on) ? BigDecimal::zero() : $r(KyExcise::CHEW_PCT);
        $chewPctTax = $chewPctNet->multipliedBy($chewPctRate);
        $chewTotal = $cs->plus($ch)->plus($cp)->plus($over16)->plus($chewPctTax);
        $chewNet = $chewTotal->minus($chewTotal->multipliedBy($disc));
        $premTax = $start->greaterThanOrEqualTo('2026-07-01') ? $premium->multipliedBy($r(KyExcise::PREMIUM_CIGAR_PCT)) : BigDecimal::zero();
        $l16 = $l7->plus($l14)->plus($chewNet);

        $poLines = PurchaseOrderLine::with(['purchaseOrder.vendor', 'product'])->whereHas('purchaseOrder', fn ($q) => $q->whereBetween('received_at', [$start->startOfDay(), $end->endOfDay()]))->where('excise_accrued', '<>', 0)->get();
        $accrued = Money::of($poLines->sum('excise_accrued'));
        foreach ($poLines as $pl) {
            $ref('accrual', 'po', $pl->purchaseOrder, $pl->purchaseOrder->vendor?->name, $pl->product, $pl->qty_received, $pl->excise_accrued);
        }
        $chewAll = ['chew_single', 'chew_single_ret', 'chew_half_pound', 'chew_half_pound_ret', 'chew_pound', 'chew_pound_ret', 'chew_over_16', 'chew_over_16_ret', 'chew_pct', 'chew_pct_ret'];
        $drillMap = [
            '1' => ['otp'], '2' => ['otp_ret'], '3' => ['otp', 'otp_ret'], '5' => ['otp', 'otp_ret'], '6' => ['otp', 'otp_ret'], '7' => ['otp', 'otp_ret'],
            '8' => ['snuff'], '9' => ['snuff_ret'], '10' => ['snuff', 'snuff_ret'], '12' => ['snuff', 'snuff_ret'], '13' => ['snuff', 'snuff_ret'], '14' => ['snuff', 'snuff_ret'],
            '15' => $chewAll, '16' => array_merge(['otp', 'otp_ret', 'snuff', 'snuff_ret'], $chewAll),
            '18/23/28' => ['chew_single_ret', 'chew_half_pound_ret', 'chew_pound_ret', 'chew_over_16_ret'],
            '17-21' => ['chew_single', 'chew_single_ret'], '22-26' => ['chew_half_pound', 'chew_half_pound_ret'], '27-31' => ['chew_pound', 'chew_pound_ret'],
            '32-39' => ['chew_over_16', 'chew_over_16_ret'], 'C%' => ['chew_pct', 'chew_pct_ret'], '40' => $chewAll, 'S1' => ['premium', 'premium_ret'], 'S2' => ['vapor'], 'R1' => ['accrual'],
        ];

        $notes += [
            'form' => 'Line numbers follow the 73A422 instructions (rev. 8-13). Lines 2/9/18/23/28 adjustments = posted credit memos (customer returns) dated in the period for KY ship-tos; chew page-2 lines are net of returns (lines 18/23/28 placement is a guess). Chew page-2 line numbers 17-40 are approximated from the instructions (guess).',
            'premium' => 'Premium cigars (6%, from 2026-07-01, HB 757) are not on the 8-13 form; shown as a supplemental line (guess at placement). Line 16 total excludes it until the current MyTaxes layout is confirmed.',
            'timing' => 'This return is based on SALES delivered to KY ship-tos in the period (per form). The ERP accrues KY excise on snuff/chew at PO RECEIPT (account 2100); see the reconciliation line. Differences = unsold inventory.',
            'rates' => 'Rates come from the tax_rates table; all seeded rows are marked VERIFY.',
        ];

        return [
            'title' => $this->title(), 'form' => 'KY 73A422', 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'tax_due' => $c2($l16->plus($premTax)),
            'summary' => [
                ['1', 'Tobacco products sales price (excl. snuff, chew, premium cigars)', $c2($otp)],
                ['2', 'Adjustments (returns / credit memos)', $c2($adj['otp']->negated())],
                ['3', 'Net sales', $c2($otpNet)],
                ['4', 'Rate', (string) $r(KyExcise::OTP_PCT)->toScale(4)],
                ['5', 'Tobacco products tax', $c2($l5)],
                ['6', 'Distributor discount', $c2($l6)],
                ['7', 'Net tobacco products tax', $c2($l7)],
                ['8', 'Snuff units sold (1.5 oz increments)', $u($snuffUnits)],
                ['9', 'Adjustments (units, returns)', $u($adj['snuff']->negated())],
                ['10', 'Net snuff units', $u($snuffNet)],
                ['11', 'Snuff rate per 1.5 oz', (string) $r(KyExcise::SNUFF)->toScale(2)],
                ['12', 'Snuff tax', $c2($l12)],
                ['13', 'Distributor discount', $c2($l13)],
                ['14', 'Net snuff tax', $c2($l14)],
                ['15', 'Chewing tobacco tax (page 2, line 40)', $c2($chewNet)],
                ['16', 'TOTAL TAX DUE (7 + 14 + 15)', $c2($l16)],
                ['18/23/28', 'Chew returns (units) single / half-pound / pound; over-16oz tax', $u($adj['single']).' / '.$u($adj['half_pound']).' / '.$u($adj['pound']).' / '.$c2($adj['over16'])],
                ['17-21', 'Chew single units (<4 oz) / tax', $u($chew['single']).' / '.$c2($cs)],
                ['22-26', 'Chew half-pound units (4-8 oz) / tax', $u($chew['half_pound']).' / '.$c2($ch)],
                ['27-31', 'Chew pound units (>8-16 oz) / tax', $u($chew['pound']).' / '.$c2($cp)],
                ['32-39', 'Chew over 16 oz tax (guess at line range)', $c2($over16)],
                ['C%', 'Chew at % of price (from switch date): net sales / rate / tax', $c2($chewPctNet).' / '.(string) $chewPctRate->toScale(4).' / '.$c2($chewPctTax)],
                ['40', 'Total chewing tobacco tax', $c2($chewTotal)],
                ['S1', 'Supplemental: premium cigar sales / 6% tax', $c2($premium).' / '.$c2($premTax)],
                ['S2', 'Reference: vapor closed cartridges / open-system sales', $u($vaporClosedUnits).' / '.$c2($vaporOpen)],
                ['R1', 'Reconciliation: KY excise accrued at PO receipt this period (acct 2100)', $c2($accrued)],
            ],
            'sections' => [
                'detail' => ['title' => 'Detail: KY sales of untaxed tobacco products',
                    'columns' => ['Delivered', 'SO', 'Customer', 'SKU', 'Product', 'Class', 'Net oz/unit', 'Qty', 'Consumer units', 'Sales $', 'Basis', 'Tax'],
                    'rows' => $detail],
                'returns' => ['title' => 'Adjustments: customer returns (credit memos)',
                    'columns' => ['Memo date', 'CM', 'Customer', 'SKU', 'Product', 'Qty', 'Consumer units', 'Credit $', 'Disposition'],
                    'rows' => $returns],
            ],
            'notes' => array_values($notes),
            'drill' => self::drill($drillMap, $refs),
        ];
    }
}
