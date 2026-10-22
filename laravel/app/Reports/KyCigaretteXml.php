<?php

namespace App\Reports;

use App\Models\CreditMemoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Services\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DOMElement;

/**
 * KY Cigarette return XML (ReturnType CIG) for a Resident Subjobber, per the Kentucky Cigarette EDI Guide V1.7 (5-8-2023)
 * Table 1 and Tables 2-10, validated against the official public schema KYCigarette2017V1.4 (resources/ky-edi).
 * Subjobbers may use schedules 1B, 1C, 2B, 2C (guide Table 4). SchUnaffixedStampReport (stamping agents) and the
 * optional SchPack inventory schedule are not produced.
 */
class KyCigaretteXml extends KyTobaccoXml
{
    public const SCHEMA_VERSION = 'KYCigarette2017V1.4';

    public const XSD = 'ky-edi/KYCigarette2017V1.4/Cigarette/CigaretteTransmission.xsd';

    protected const ROOT = 'CigaretteTransmission';

    protected const RETURN_TYPE = 'CIG';

    protected function schedules(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        $mv = StockMovement::with('product')->whereHas('product', fn ($q) => $q->where('tax_class', 'cigarette_stamped'))
            ->whereIn('type', ['receipt', 'sale'])->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])->orderBy('created_at')->get();
        foreach ($mv->where('type', 'receipt')->where('reference_type', (new PurchaseOrder)->getMorphClass())->groupBy('reference_id') as $poId => $ms) {
            $po = PurchaseOrder::with('vendor')->find($poId);
            $v = $po->vendor;
            if ($v->is_manufacturer) {
                $this->warnings['cig_mfr'] = "{$po->number}: received from a vendor flagged as manufacturer; subjobbers may only report 1B/1C, reported on 1B.";
            }
            $out['1B'][] = ['date' => $ms->first()->created_at->toDateString(), 'doc' => 'Invoice', 'number' => $po->number,
                'ctype' => $v->is_manufacturer ? 'Manufacturer' : 'Distributor', 'name' => $v->name, 'addr' => [$v->address, $v->city, $v->state, $v->zip],
                'ein' => $v->fein, 'state_ein' => $v->ky_license_number, 'cid' => $v->code,
                'lines' => $ms->map(fn ($m) => [$m->product, $m->qty, Money::mul($m->qty, $m->unit_cost ?? $m->product->cost), 'KY'])->all()];
        }
        foreach ($mv->where('type', 'sale')->where('reference_type', (new SalesOrder)->getMorphClass())->groupBy('reference_id') as $soId => $ms) {
            $so = SalesOrder::with(['customer', 'shipTo', 'lines'])->find($soId);
            $c = $so->customer;
            $code = \App\Support\CustomerType::isLicensee($c->type) ? '2B' : '2C';
            $out[$code][] = $this->customerDoc($c, $so->location(), $ms->first()->created_at->toDateString(), 'Invoice', $so->number,
                $ms->map(function ($m) use ($so) {
                    $line = $so->lines->firstWhere('product_id', $m->product_id);
                    $qty = Money::of($m->qty)->abs();

                    return [$m->product, $qty, $line ? Money::mul($qty, $line->unit_price) : 0, $so->location()->state === 'KY' ? 'KY' : $so->location()->state];
                })->all());
        }
        $cml = CreditMemoLine::with(['product', 'creditMemo.customer', 'creditMemo.salesOrder.shipTo'])
            ->whereHas('product', fn ($q) => $q->where('tax_class', 'cigarette_stamped'))
            ->whereHas('creditMemo', fn ($q) => $q->where('status', 'posted')->whereBetween('memo_date', [$start->toDateString(), $end->toDateString()]))->get();
        foreach ($cml->groupBy('credit_memo_id') as $lines) {
            $cm = $lines->first()->creditMemo;
            $code = \App\Support\CustomerType::isLicensee($cm->customer->type) ? '1B' : '1C';
            $out[$code][] = $this->customerDoc($cm->customer, $cm->salesOrder->location(), $cm->memo_date->toDateString(), 'Credit Memo', $cm->number,
                $lines->map(fn ($l) => [$l->product, $l->qty, $l->line_total, 'KY'])->all());
        }
        ksort($out);

        return $out;
    }

    /** Transactions (TransactionsType = CigInventoryType + Price). Quantity is in UPCUOM units. */
    protected function transaction(DOMElement $t, Product $p, $qty, $total, string $jur): void
    {
        $per = (int) ($p->sticks_per_unit ?: 0);
        if ($per === 0) {
            $this->warnings["spu{$p->id}"] = "{$p->sku}: sticks per unit not set; assumed carton of 200.";
            $per = 200;
        }
        $uom = match (true) {
            $per >= 1000 => 'CSE', $per >= 200 => 'CAR', $per > 1 => 'PAK', default => 'STK'
        };
        $q = Money::of($qty)->abs()->toScale(0, RoundingMode::HALF_UP);
        $sticksPerPack = $per >= 200 ? 20 : $per; // KY packs are 20 or 25; assumes 20 for cartons/cases
        $x = $this->el($t, 'Transactions');
        $this->el($x, 'FedDesc', 'Cigarette');
        $this->el($x, 'MSAStatus', $p->msa_status ?: 'N/A');
        $this->el($x, 'TaxJurisdiction', $jur ?: 'KY');
        $upc = preg_replace('/\D/', '', (string) $p->upc);
        if ($upc === '') {
            $this->warnings["upc{$p->id}"] = "{$p->sku}: UPC missing (UPCNumber required).";
        }
        $this->el($x, 'UPCNumber', substr($upc ?: '0', 0, 25));
        $this->el($x, 'UPCUOM', $uom);
        if (! $p->manufacturer) {
            $this->warnings["mfr{$p->id}"] = "{$p->sku}: manufacturer name missing (used brand).";
        }
        $this->el($x, 'Manufacturer', $this->clip($p->manufacturer ?: ($p->brand ?: 'Unknown'), 85));
        $this->el($x, 'ManufacturerEIN', $this->ein($p->manufacturer_fein, "{$p->sku} manufacturer FEIN"));
        $this->el($x, 'BrandFamily', $this->clip($p->brand ?: $p->name, 85));
        $this->el($x, 'Quantity', (string) $q);
        $this->el($x, 'SticksPerPack', (string) $sticksPerPack);
        $this->el($x, 'TotalSticks', (string) $q->multipliedBy($per));
        $this->el($x, 'Price', $this->d2($total));
    }
}
