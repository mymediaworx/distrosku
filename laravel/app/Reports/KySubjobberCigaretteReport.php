<?php

namespace App\Reports;

use App\Models\CreditMemoLine;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Services\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * KY Resident Subjobber monthly cigarette return (MyTaxes, FTA XML EDI). Per the KY Resident Subjobber Filer Guide:
 * 1B receipts from non-manufacturer licensees, 1C receipts from retailers/end users,
 * 2B disbursements to licensees (wholesaler/distributor/subjobber), 2C disbursements to retailers/end users.
 * Due the 20th of the following month. Vega buys KY-stamped cigarettes, so no tax is due: report only.
 */
class KySubjobberCigaretteReport extends TaxReport
{
    public const COLUMNS = ['Schedule', 'Date', 'Doc type', 'Doc #', 'Customer/Supplier', 'Type', 'FEIN', 'Address', 'State', 'UPC', 'Description', 'Qty (units)', 'Cartons', 'Sticks'];

    public function key(): string
    {
        return 'ky_subjobber_cigarette';
    }

    public function title(): string
    {
        return 'KY Resident Subjobber Cigarette Return';
    }

    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = fn () => StockMovement::query()->with('product')
            ->whereHas('product', fn ($q) => $q->where('tax_class', 'cigarette_stamped'));
        $inPeriod = fn () => $base()->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()]);
        $notes = [];
        $sticks = function ($m, $qty) use (&$notes) {
            $per = (int) ($m->product->sticks_per_unit ?: 0);
            if ($per === 0) {
                $notes[$m->product->sku] = "{$m->product->sku}: sticks per unit not set; assumed carton = 200 (guess).";
                $per = 200;
            }

            return Money::of($qty)->abs()->multipliedBy($per);
        };

        $receipts = [];
        $disb = [];
        $totIn = Money::of(0);
        $totOut = Money::of(0);
        $refs = ['in' => [], 'out' => []];
        $mk = fn ($type, $doc, $date, $party, $p, $qty) => ['type' => $type, 'id' => $doc?->id, 'number' => $doc?->number, 'date' => $date, 'party' => $party, 'sku' => $p->sku, 'qty' => Money::s($qty), 'amount' => ''];
        foreach ($inPeriod()->whereIn('type', ['receipt', 'sale'])->orderBy('created_at')->get() as $m) {
            $st = $sticks($m, $m->qty);
            $row = fn ($sched, $doc, $party, $ptype, $fein, $addr, $state) => [
                $sched, $m->created_at->toDateString(), 'Invoice', $doc, $party, $ptype, $fein, $addr, $state,
                $m->product->upc, $m->product->name, Money::s(Money::of($m->qty)->abs()), (string) $st->dividedBy(200, 2, RoundingMode::HALF_UP), (string) $st->toScale(0)];
            if ($m->type === 'receipt' && $m->reference_type === (new PurchaseOrder)->getMorphClass()) {
                $po = PurchaseOrder::with('vendor')->find($m->reference_id);
                $v = $po?->vendor;
                $receipts[] = $row('1B', $po?->number, $v?->name, $v?->is_manufacturer ? 'Manufacturer' : 'Distributor', $v?->fein, $v?->address, $v?->state);
                if ($v?->is_manufacturer) {
                    $notes['mfr'] = 'A receipt is from a vendor flagged as manufacturer; the subjobber guide only lists 1B/1C. Reported on 1B. Check with KY DOR.';
                }
                $refs['in'][] = $mk('po', $po, $m->created_at->toDateString(), $v?->name, $m->product, $m->qty);
                $totIn = $totIn->plus($st);
            } elseif ($m->type === 'sale' && $m->reference_type === (new SalesOrder)->getMorphClass()) {
                $so = SalesOrder::with('customer', 'shipTo')->find($m->reference_id);
                $c = $so?->customer;
                $licensee = in_array($c?->type, ['wholesaler', 'subjobber', 'distributor'], true);
                $disb[] = $row($licensee ? '2B' : '2C', $so?->number, $c?->name, $licensee ? 'Licensee' : 'Retailer', $c?->fein,
                    trim(($so?->shipTo?->address ?? '').' '.($so?->shipTo?->city ?? '')), $so?->shipTo?->state);
                $refs['out'][] = $mk('so', $so, $m->created_at->toDateString(), $c?->name, $m->product, Money::of($m->qty)->abs());
                $totOut = $totOut->plus($st);
            }
        }

        // 1C: returns from retailers/end users (posted credit memos). Restocked returns also appear as 'return' stock movements in inventory.
        $cml = CreditMemoLine::with(['product', 'creditMemo.customer', 'creditMemo.salesOrder.shipTo'])
            ->whereHas('product', fn ($q) => $q->where('tax_class', 'cigarette_stamped'))
            ->whereHas('creditMemo', fn ($q) => $q->where('status', 'posted')->whereBetween('memo_date', [$start->toDateString(), $end->toDateString()]))->get();
        foreach ($cml as $l) {
            $cm = $l->creditMemo;
            $per = (int) ($l->product->sticks_per_unit ?: 200);
            $st = Money::of($l->qty)->multipliedBy($per);
            $licensee = in_array($cm->customer->type, ['wholesaler', 'subjobber', 'distributor'], true);
            $receipts[] = [$licensee ? '1B' : '1C', $cm->memo_date->toDateString(), 'Credit Memo', $cm->number, $cm->customer->name, $licensee ? 'Licensee' : 'Retailer',
                $cm->customer->fein, trim(($cm->salesOrder->shipTo->address ?? '').' '.($cm->salesOrder->shipTo->city ?? '')), $cm->salesOrder->shipTo->state ?? '',
                $l->product->upc, $l->product->name.($l->disposition === 'damage' ? ' (damaged)' : ''), Money::s($l->qty), (string) $st->dividedBy(200, 2, RoundingMode::HALF_UP), (string) $st->toScale(0)];
            $refs['in'][] = $mk('cm', $cm, $cm->memo_date->toDateString(), $cm->customer->name, $l->product, $l->qty);
            $totIn = $totIn->plus($st);
        }

        $sumBefore = fn ($date) => (string) $base()->where('created_at', '<', $date)->get()
            ->reduce(fn ($c, $m) => $c->plus(Money::of($m->qty)->multipliedBy($m->product->sticks_per_unit ?: 200)), Money::of(0))->toScale(0);
        $begin = $sumBefore($start->startOfDay());
        $endInv = $sumBefore($end->addDay()->startOfDay());

        return [
            'title' => $this->title(), 'form' => 'MyTaxes cigarette return (FTA XML EDI), Resident Subjobber',
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'tax_due' => '0',
            'summary' => [
                ['A', 'Beginning inventory (sticks, all KY-stamped)', $begin],
                ['B', 'Receipts 1B/1C (sticks)', (string) $totIn->toScale(0)],
                ['C', 'Disbursements 2B/2C (sticks)', (string) $totOut->toScale(0)],
                ['D', 'Other adjustments (sticks) = A + B - C - E', (string) Money::of($begin)->plus($totIn)->minus($totOut)->minus(Money::of($endInv))->toScale(0)],
                ['E', 'Ending inventory (sticks)', $endInv],
                ['F', 'Tax due (stamps already affixed by supplier)', '0.00'],
            ],
            'sections' => [
                'receipts' => ['title' => 'Receipts (Schedules 1B / 1C)', 'columns' => self::COLUMNS, 'rows' => $receipts],
                'disbursements' => ['title' => 'Disbursements (Schedules 2B / 2C)', 'columns' => self::COLUMNS, 'rows' => $disb],
            ],
            'drill' => self::drill(['B' => ['in'], 'C' => ['out'], 'D' => ['in', 'out']], $refs),
            'notes' => array_values($notes + [
                'guide' => 'Layout follows the KY Resident Subjobber Filer Guide (schedules 1B/1C/2B/2C, header: FEIN, KY cigarette license #, period end). The summary lines A-F are an internal reconciliation, not form line numbers.',
                'dates' => 'Transaction date = stock movement date (receipt / delivery). Customer returns come from posted credit memos (1C; 1B if the customer is a licensee); damaged returns are not restocked, so they show up in line D.',
                'type' => 'Customer type wholesaler/subjobber/distributor => 2B, everyone else => 2C.',
            ]),
        ];
    }
}
