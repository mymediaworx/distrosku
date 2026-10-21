<?php

namespace App\Reports;

use App\Enums\TaxClass;
use App\Models\CreditMemoLine;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrderLine;
use App\Models\Setting;
use App\Services\KyExcise;
use App\Services\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;

/**
 * KY Tobacco Products tax return XML (ReturnType TOB) per "Commonwealth of Kentucky XML Electronic Filing for Tobacco
 * Products Data" EDI Guide v1.9 (07/01/2026), Table 1 XPaths, Tables 3-12 codes. Element order follows the KY XSD
 * (KYTobacco2017V1.4 public schema + documented v1.5 changes; see resources/ky-edi). No namespace (per KY schema).
 *
 * Schedules produced: 1A/1B receipts (PO receipts of KY-untaxed product), 1C returns (posted credit memos),
 * 2B/2C disbursements (KY sales). 1D, 2A (manufacturer only) and 2D (returns to manufacturer, not modelled) are not produced.
 */
class KyTobaccoXml
{
    public const SCHEMA_VERSION = 'KYTobacco2017V1.5';

    public const XSD = 'ky-edi/KYTobacco2017V1.5-local/Tobacco/TobaccoTransmission.xsd';

    protected const ROOT = 'TobaccoTransmission';

    protected const RETURN_TYPE = 'TOB';

    /** @var string[] data problems found while generating (product/customer fields to fix) */
    public array $warnings = [];

    protected const CLASSES = ['snuff_chew', 'cigar', 'pipe', 'ryo', 'vapor'];

    public static function filerSettingKeys(): array
    {
        return ['ky_filer_fein' => 'Filer FEIN (9 digits)', 'ky_filer_license' => 'KY tobacco license / account no. (StateEIN)',
            'ky_filer_name' => 'Legal business name', 'ky_filer_address' => 'Street address', 'ky_filer_city' => 'City',
            'ky_filer_state' => 'State', 'ky_filer_zip' => 'ZIP (5 or 9 digits)', 'ky_rep_first' => 'Representative first name',
            'ky_rep_last' => 'Representative last name', 'ky_rep_title' => 'Representative title', 'ky_rep_phone' => 'Representative phone (10 digits)',
            'ky_rep_email' => 'Representative email'];
    }

    public function generate(CarbonImmutable $start, CarbonImmutable $end, bool $amended = false, string $processType = 'P', ?string $now = null): string
    {
        $this->warnings = [];
        $ts = CarbonImmutable::parse($now ?? now())->format('Y-m-d\TH:i:sP');
        $f = fn ($k, $d = '') => (string) (Setting::get($k) ?: $d);
        $license = $this->clip(preg_replace('/\s+/', '', $f('ky_filer_license')), 20);
        if ($license === '') {
            $this->warnings['filer_license'] = 'Filer KY license (StateEIN) is not set (Settings > KY filer).';
        }

        $d = new DOMDocument('1.0', 'utf-8');
        $d->formatOutput = true;
        $root = $d->appendChild($d->createElement(static::ROOT));
        $th = $this->el($root, 'TransmissionHeader');
        $th->setAttribute('recordCount', '1');
        $this->el($th, 'Jurisdiction', 'KY');
        $this->el($th, 'TransmissionId', substr('VEGA'.$end->format('Ym').strtoupper(bin2hex(random_bytes(8))), 0, 30));
        $this->el($th, 'Timestamp', $ts);
        $this->el($this->el($th, 'Transmitter'), 'StateEIN', $license ?: '0');
        $this->el($th, 'ProcessType', in_array($processType, ['T', 'P'], true) ? $processType : 'P');

        $rs = $this->el($root, 'ReturnState');
        $rs->setAttribute('stateSchemaVersion', static::SCHEMA_VERSION);
        $this->el($rs, 'SubmissionId', substr($end->format('Ym').strtoupper(bin2hex(random_bytes(7))), 0, 20));
        $h = $this->el($rs, 'ReturnHeaderState');
        $h->setAttribute('binaryAttachmentCount', '0');
        $this->el($h, 'Jurisdiction', 'KY');
        $this->el($h, 'Timestamp', $ts);
        $this->el($h, 'TaxPeriodBeginDate', $start->toDateString());
        $this->el($h, 'TaxPeriodEndDate', $end->toDateString());
        if ($amended) {
            $this->el($h, 'AmendedReturnIndicator', 'X'); // ReturnHeaderSO.xsd order: after SignatureOption, before ReturnType
        }
        $this->el($h, 'ReturnType', static::RETURN_TYPE);
        $filer = $this->el($h, 'Filer');
        $this->el($filer, 'EIN', $this->ein($f('ky_filer_fein'), 'filer FEIN (Settings)'));
        if ($license !== '') {
            $this->el($filer, 'StateEIN', $license);
        }
        $this->el($this->el($filer, 'Name'), 'BusinessNameLine1Txt', $this->biz($f('ky_filer_name', config('erp.company'))));
        $this->usAddress($this->el($filer, 'USAddress'), $f('ky_filer_address'), $f('ky_filer_city'), $f('ky_filer_state', 'KY'), $f('ky_filer_zip'), 'filer', 'StateAbbreviationCd', 'ZIPCd');
        $rep = $this->el($h, 'BusinessRepresentative');
        $pn = $this->el($rep, 'PersonName');
        $this->el($pn, 'FirstName', $this->name($f('ky_rep_first'), 16, 'representative first name'));
        $this->el($pn, 'LastName', $this->name($f('ky_rep_last'), 32, 'representative last name'));
        $this->el($rep, 'PersonTitleTxt', $this->clip($f('ky_rep_title', 'Owner'), 35));
        $phone = preg_replace('/\D/', '', $f('ky_rep_phone'));
        if (strlen($phone) === 10) {
            $this->el($rep, 'PhoneNum', $phone);
        } else {
            $this->warnings['phone'] = 'Representative phone (10 digits) not set; PhoneNum omitted.';
        }
        if ($f('ky_rep_email') !== '') {
            $this->el($rep, 'EmailAddressTxt', $this->clip($f('ky_rep_email'), 75));
        }

        $sch = $this->el($this->el($rs, 'ReturnDataState'), 'SchTransaction');
        $schedules = $this->schedules($start, $end);
        if (! $schedules) {
            $this->el($sch, 'NoTransactions', 'X');
        }
        foreach ($schedules as $code => $docs) {
            $s = $this->el($sch, 'Schedule');
            $this->el($s, 'TypeOfSchedule', $code);
            foreach ($docs as $doc) {
                $this->document($s, $doc);
            }
        }

        return $d->saveXML();
    }

    /** Validate against the bundled XSD. Returns list of error strings (empty = valid). */
    public static function validate(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $d = new DOMDocument;
        $d->loadXML($xml);
        $ok = $d->schemaValidate(resource_path(static::XSD));
        $errs = array_map(fn ($e) => trim($e->message)." (line {$e->line})", libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? [] : $errs;
    }

    /** @return array<string, array<int, array>> schedule code => documents */
    protected function schedules(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        $range = [$start->startOfDay(), $end->endOfDay()];

        // 1A / 1B receipts: product received from vendors that do not pay KY tax for us.
        $pol = PurchaseOrderLine::with(['product', 'purchaseOrder.vendor'])->where('qty_received', '>', 0)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereBetween('received_at', $range))
            ->whereHas('product', fn ($q) => $q->whereIn('tax_class', self::CLASSES)->where('ky_tax_paid_by_supplier', false))->get();
        foreach ($pol->groupBy('purchase_order_id') as $lines) {
            $po = $lines->first()->purchaseOrder;
            $v = $po->vendor;
            $code = $v->is_manufacturer ? '1A' : '1B';
            $out[$code][] = ['date' => $po->received_at->toDateString(), 'doc' => 'Invoice', 'number' => $po->number,
                'ctype' => $v->is_manufacturer ? 'Manufacturer' : 'Distributor', 'name' => $v->name,
                'addr' => [$v->address, $v->city, $v->state, $v->zip], 'ein' => $v->fein, 'state_ein' => $v->ky_license_number, 'cid' => $v->code,
                'lines' => $lines->map(fn ($l) => [$l->product, $l->qty_received, Money::mul($l->qty_received, $l->unit_cost), 'KY'])->all()];
        }

        // 1C returns from retailers / end users (posted credit memos).
        $cml = CreditMemoLine::with(['product', 'creditMemo.customer', 'creditMemo.salesOrder.shipTo'])
            ->whereHas('creditMemo', fn ($q) => $q->where('status', 'posted')->whereBetween('memo_date', [$start->toDateString(), $end->toDateString()])
                ->whereHas('salesOrder.shipTo', fn ($s) => $s->where('state', 'KY')))
            ->whereHas('product', fn ($q) => $q->whereIn('tax_class', self::CLASSES)->where('ky_tax_paid_by_supplier', false))->get();
        foreach ($cml->groupBy('credit_memo_id') as $lines) {
            $cm = $lines->first()->creditMemo;
            $c = $cm->customer;
            $code = in_array($c->type, ['wholesaler', 'subjobber', 'distributor'], true) ? '1B' : '1C';
            $out[$code][] = $this->customerDoc($c, $cm->salesOrder->location(), $cm->memo_date->toDateString(), 'Credit Memo', $cm->number,
                $lines->map(fn ($l) => [$l->product, $l->qty, $l->line_total, 'KY'])->all());
        }

        // 2B / 2C disbursements: KY deliveries in the period.
        $sol = SalesOrderLine::with(['product', 'salesOrder.customer', 'salesOrder.shipTo'])
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', ['delivered', 'invoiced'])->whereBetween('delivered_at', $range)
                ->whereHas('shipTo', fn ($s) => $s->where('state', 'KY')))
            ->whereHas('product', fn ($q) => $q->whereIn('tax_class', self::CLASSES)->where('ky_tax_paid_by_supplier', false))->get();
        foreach ($sol->groupBy('sales_order_id') as $lines) {
            $so = $lines->first()->salesOrder;
            $c = $so->customer;
            $code = in_array($c->type, ['wholesaler', 'subjobber', 'distributor'], true) ? '2B' : '2C';
            $out[$code][] = $this->customerDoc($c, $so->location(), $so->delivered_at->toDateString(), 'Invoice', $so->number,
                $lines->map(fn ($l) => [$l->product, $l->qty, $l->line_total, 'KY'])->all());
        }
        ksort($out);

        return $out;
    }

    protected function customerDoc(Customer $c, $shipTo, string $date, string $docType, string $number, array $lines): array
    {
        $lic = $c->licenses()->where('state', 'KY')->whereIn('type', ['tobacco_retail', 'tobacco_wholesale'])->value('number');

        return ['date' => $date, 'doc' => $docType, 'number' => $number,
            'ctype' => match ($c->type) {
                'retailer' => 'Retailer', 'wholesaler' => 'Wholesaler', 'subjobber' => 'Subjobber', 'distributor' => 'Distributor', default => 'Customer'
            },
            'name' => $c->name, 'addr' => [$shipTo?->address, $shipTo?->city, $shipTo?->state, $shipTo?->zip],
            // Guide Table 1: EIN 999999999 / StateEIN 999999999 for end consumers or when not available.
            'ein' => $c->fein ?: '999999999', 'state_ein' => $lic ?: '999999999', 'cid' => $c->code, 'lines' => $lines];
    }

    protected function document(DOMElement $s, array $doc): void
    {
        $t = $this->el($s, 'TransactionDocument');
        $this->el($t, 'Date', $doc['date']);
        $this->el($t, 'TypeOfTransDoc', $doc['doc']);
        $this->el($t, 'DocNumber', $this->clip($doc['number'], 20));
        $this->el($t, 'TypeOfCustomer', $doc['ctype']);
        $this->el($this->el($t, 'Name'), 'BusinessNameLine1Txt', $this->biz($doc['name']));
        $a = $this->el($t, 'Address');
        [$st, $city, $state, $zip] = $doc['addr'];
        $this->usAddress($this->el($a, 'USAddress'), (string) $st, (string) $city, (string) ($state ?: 'KY'), (string) $zip, "{$doc['number']} address", 'StateAbbreviationCd', 'ZIPCd');
        $this->el($a, 'TypeOfAddress', 'Location'); // Table 11: only "Location" is used by KY
        $this->el($t, 'EIN', $this->ein($doc['ein'], "{$doc['name']} FEIN", '999999999'));
        $this->el($t, 'StateEIN', $this->clip($doc['state_ein'] ?: '999999999', 20));
        $this->el($t, 'CustomerID', $this->clip($doc['cid'], 35));
        foreach ($doc['lines'] as [$p, $qty, $total, $jur]) {
            $this->transaction($t, $p, $qty, $total, $jur);
        }
    }

    /** One Transactions element. Table 3 calculation: Unit*Quantity for unit-taxed codes, Value*Quantity for value-taxed codes. */
    protected function transaction(DOMElement $t, Product $p, $qty, $total, string $jur): void
    {
        $units = KyExcise::consumerUnits($p, $qty);
        $q = $units->toScale(0, RoundingMode::HALF_UP);
        [$fed, $state, $byValue, $unit] = $this->codes($p);
        $value = $q->isZero() ? BigDecimal::zero() : Money::of($total)->dividedBy($units, 2, RoundingMode::HALF_UP);
        $x = $this->el($t, 'Transactions');
        $this->el($x, 'FedDesc', $fed);
        $this->el($x, 'StateDesc', $state);
        $msa = $p->msa_status ?: 'N/A';
        $this->el($x, 'MSAStatus', $msa);
        $this->el($x, 'Price', $this->d2($total));
        $this->el($x, 'TaxJurisdiction', $jur);
        $this->el($x, 'ProductDescription', $this->clip($p->name, 85));
        $mfr = $p->manufacturer ?: $p->brand;
        if (! $p->manufacturer) {
            $this->warnings["mfr{$p->id}"] = "{$p->sku}: manufacturer name missing (used brand).";
        }
        $this->el($x, 'Manufacturer', $this->clip($mfr ?: 'Unknown', 85));
        $this->el($x, 'ManufacturerEIN', $this->ein($p->manufacturer_fein, "{$p->sku} manufacturer FEIN"));
        $this->el($x, 'BrandFamily', $this->clip($p->brand ?: $p->name, 85));
        $this->el($x, 'Unit', (string) $unit);
        $this->el($x, 'UnitDescription', $this->unitDesc($p));
        $this->el($x, 'WeightVolume', $this->d2($p->net_weight_oz ?? 0));
        $this->el($x, 'Value', (string) $value);
        $this->el($x, 'Quantity', (string) $q);
        $this->el($x, 'StickCount', (string) ($p->tax_class === TaxClass::Cigar ? $q->multipliedBy(max(1, (int) ($p->sticks_per_unit ?? 1))) : 0));
        $this->el($x, 'ExtendedTaxableAmount', $byValue ? $this->d2($total) : (string) $q->multipliedBy($unit));
    }

    /** @return array{0:string,1:string,2:bool,3:int} FedDesc (Table 9), StateDesc (Table 3), value-based?, Unit */
    public function codes(Product $p): array
    {
        $fed = match ($p->tax_class) {
            TaxClass::SnuffChew => ($p->smokeless_type ?? 'snuff') === 'snuff' ? 'Snuff' : 'Chewing Tobacco',
            TaxClass::Cigar => $p->premium_cigar ? 'Large Cigar' : 'Small Cigar',
            TaxClass::Pipe => 'Pipe Tobacco', TaxClass::Ryo => 'Roll Your Own', TaxClass::Vapor => 'Vapor Product', default => 'Other',
        };
        $state = $p->ky_state_desc;
        if (! $state) {
            $state = match (true) {
                $p->tax_class === TaxClass::SnuffChew && ($p->smokeless_type ?? 'snuff') === 'snuff' => 'KY-MS1',
                $p->tax_class === TaxClass::SnuffChew => 'KY-LL'.match (KyExcise::chewBucket($p->net_weight_oz ?? 0)) {
                    'single' => 1, 'half_pound' => 2, 'pound' => 3, default => 4
                },
                $p->tax_class === TaxClass::Cigar && $p->premium_cigar => 'KY-LCGR1',
                $p->tax_class === TaxClass::Vapor => ($p->category && str_contains(strtolower($p->category), 'open')) ? 'KY-VAP2' : 'KY-VAP1',
                default => 'KY-TP1',
            };
            if ($p->tax_class === TaxClass::SnuffChew) {
                $this->warnings["sd{$p->id}"] = "{$p->sku}: StateDesc defaulted to {$state} (set 'KY state desc' on the product if dry snuff/snus/plug/twist).";
            }
        }
        $byValue = in_array(preg_replace('/-MRT\d$/', '', $state), ['KY-TP1', 'KY-LCGR1', 'KY-VAP2'], true);
        $unit = str_starts_with($state, 'KY-MS') || str_starts_with($state, 'KY-DS') || str_starts_with($state, 'KY-SS')
            ? KyExcise::snuffIncrements($p->net_weight_oz ?? 1.5) : 1;

        return [$fed, $state, $byValue, max(1, $unit)];
    }

    protected function unitDesc(Product $p): string
    {
        $ok = ['PAK', 'STK', 'BOX', 'ECH', 'BAG', 'TIN', 'FOI', 'CAN', 'BUL', 'TUB', 'PCH', 'BUN', 'PLG', 'CUT', 'BOT', 'JAR', 'OTH'];
        $u = strtoupper((string) $p->edi_unit_desc);
        if (in_array($u, $ok, true)) {
            return $u;
        }
        $map = ['can' => 'CAN', 'tin' => 'TIN', 'pouch' => 'PCH', 'pack' => 'PAK', 'box' => 'BOX', 'bag' => 'BAG', 'tub' => 'TUB', 'stick' => 'STK', 'bottle' => 'BOT', 'jar' => 'JAR'];

        return $map[strtolower((string) $p->unit)] ?? 'ECH';
    }

    protected function usAddress(DOMElement $a, string $line, string $city, string $state, string $zip, string $who, string $stTag, string $zipTag): void
    {
        $z = preg_replace('/\D/', '', $zip);
        if ($line === '' || $city === '' || ! in_array(strlen($z), [5, 9], true)) {
            $this->warnings["addr$who"] = "Incomplete address for {$who}.";
        }
        $this->el($a, 'AddressLine1Txt', $this->clip($this->txt($line) ?: 'UNKNOWN', 35));
        $this->el($a, 'CityNm', $this->clip(preg_replace('/[^A-Za-z ]/', '', $city) ?: 'UNKNOWN', 22));
        $this->el($a, $stTag, strtoupper(substr($state ?: 'KY', 0, 2)));
        $this->el($a, $zipTag, in_array(strlen($z), [5, 9], true) ? $z : '00000');
    }

    protected function ein(?string $v, string $what, ?string $fallback = null): string
    {
        $e = preg_replace('/\D/', '', (string) $v);
        if (strlen($e) === 9) {
            return $e;
        }
        if ($fallback) {
            return $fallback;
        }
        $this->warnings["ein$what"] = "Missing/invalid {$what}; 000000000 written.";

        return '000000000';
    }

    protected function name(string $v, int $max, string $what): string
    {
        $v = trim(preg_replace("/[^A-Za-z0-9'\\- ]/", '', $v));
        if ($v === '') {
            $this->warnings[$what] = "Missing {$what} (Settings > KY filer).";

            return 'Unknown';
        }

        return rtrim(substr(preg_replace('/ +/', ' ', $v), 0, $max));
    }

    /** BusinessNameLine1Type: letters, digits, # - ( ) & ' separated by single spaces, max 75 (efileTypes.xsd). */
    protected function biz(?string $v): string
    {
        $v = trim(preg_replace('/ +/', ' ', preg_replace("/[^A-Za-z0-9#\\-()&' ]/", ' ', str_replace(['.', ','], ['', ''], (string) $v))));

        return rtrim(substr($v ?: 'UNKNOWN', 0, 75));
    }

    protected function txt(?string $v): string
    {
        return trim(preg_replace('/[^A-Za-z0-9 \-\/#&\'\.,]/', '', (string) $v));
    }

    protected function clip(?string $v, int $n): string
    {
        return rtrim(mb_substr(trim((string) $v), 0, $n));
    }

    protected function d2($v): string
    {
        return (string) Money::of($v ?? 0)->toScale(2, RoundingMode::HALF_UP);
    }

    protected function el(\DOMNode $parent, string $name, ?string $text = null): DOMElement
    {
        $e = $parent->ownerDocument ? $parent->ownerDocument->createElement($name) : $parent->createElement($name);
        if ($text !== null) {
            $e->appendChild(($parent->ownerDocument ?? $parent)->createTextNode($text));
        }
        $parent->appendChild($e);

        return $e;
    }
}
