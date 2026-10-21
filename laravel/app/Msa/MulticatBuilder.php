<?php

namespace App\Msa;

use App\Enums\TaxClass;
use App\Models\Customer;
use App\Models\MsaSubmission;
use App\Models\Product;
use App\Models\ShipTo;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the weekly MSA MULTICAT (.TOB) file from invoiced/delivered sales, posted credit memos and stock movements.
 * Layout: App\Msa\Layout (docs/msa/MULTICAT_LAYOUT.md). Pre-checks: errors block generation, warnings do not.
 */
class MulticatBuilder
{
    /** Tobacco tax classes expected to carry an MSA category code (warned when missing). */
    public const TOBACCO_CLASSES = ['cigarette_stamped', 'cigar', 'pipe', 'snuff_chew', 'vapor', 'ryo'];

    /** Last completed reporting week: the most recent week-end day strictly before $today. */
    public static function defaultWeekEnding(?string $today = null): string
    {
        $d = CarbonImmutable::parse($today ?: now()->toDateString())->subDay();
        $end = MsaSettings::weekEndDay();
        while ($d->dayOfWeek !== $end) {
            $d = $d->subDay();
        }

        return $d->toDateString();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function weekRange(string $weekEnding): array
    {
        $e = CarbonImmutable::parse($weekEnding)->endOfDay();

        return [$e->subDays(6)->startOfDay(), $e];
    }

    public static function fileName(string $weekEnding): string
    {
        return CarbonImmutable::parse($weekEnding)->format('Ymd').'.TOB';
    }

    /** Suggested MSA category from the ERP tax class (admin confirms on the fix list). */
    public static function suggestCategory(Product $p): ?string
    {
        $tc = $p->tax_class instanceof TaxClass ? $p->tax_class->value : (string) $p->tax_class;

        return match ($tc) {
            'cigarette_stamped' => '003231',
            'ryo' => '003221',
            'pipe' => '003241',
            'snuff_chew' => '003211',
            'vapor' => '003292',
            'cigar' => $p->premium_cigar ? '003227' : '003251',
            default => null,
        };
    }

    public static function suggestItems(Product $p): ?int
    {
        if ($p->sticks_per_unit) {
            return (int) $p->sticks_per_unit;
        }

        return self::suggestCategory($p) === '003231' ? 200 : null;
    }

    public static function classOfTrade(Customer $c): string
    {
        if ($c->msa_class_of_trade) {
            return $c->msa_class_of_trade;
        }

        return match (strtolower((string) $c->type)) {
            'retailer', 'retail' => 'Retailer',
            'wholesaler', 'subjobber', 'sub_jobber', 'distributor' => 'Distributor',
            default => 'Other',
        };
    }

    public static function shippingNumber(ShipTo $s): string
    {
        return (string) ($s->msa_shipping_number ?: $s->id);
    }

    /** Problems on one product that would break its BID record. */
    public static function productProblems(Product $p): array
    {
        $e = [];
        $upc = Writer::digits($p->msa_upc ?: $p->upc);
        if ($upc === '') {
            $e[] = 'no UPC';
        } elseif (strlen($upc) > 14) {
            $e[] = 'UPC longer than 14 digits';
        }
        if (strlen(Writer::ascii((string) $p->sku)) > 14) {
            $e[] = 'SKU longer than 14 characters';
        }
        if (! $p->msa_category_code || ! array_key_exists($p->msa_category_code, Layout::CATEGORIES)) {
            $e[] = 'no valid MSA category code';
        }
        if (! $p->msa_items_per_unit) {
            $e[] = 'no items per selling unit';
        }
        if (BigDecimal::of((string) ($p->msa_units_per_sale ?? '1'))->isLessThanOrEqualTo(0)) {
            $e[] = 'MSA units per ERP unit must be > 0';
        }
        if ($p->msa_promo && ! $p->msa_promo_description) {
            $e[] = 'promotion without promotion description';
        }
        if ($p->msa_shipper_flag && ! in_array($p->msa_shipper_flag, ['S', 'C'], true)) {
            $e[] = 'shipper flag must be S, C or blank';
        }

        return $e;
    }

    /**
     * Build the file. Returns ['lines', 'content', 'file_name', 'errors', 'warnings', 'totals', 'preview'].
     * $opts overrides settings (test, altria, dollars, tax_jurisdiction) - used by tests and the preview.
     */
    public function build(string $weekEnding, array $opts = []): array
    {
        $s = MsaSettings::all();
        $test = $opts['test'] ?? MsaSettings::bool('msa_test_mode');
        $altria = $opts['altria'] ?? MsaSettings::bool('msa_altria_fields');
        $dollars = $opts['dollars'] ?? MsaSettings::bool('msa_report_dollars');
        $taxJur = $opts['tax_jurisdiction'] ?? ($s['msa_tax_jurisdiction'] ?: 'none');
        $created = $opts['created'] ?? now()->format('Ymd');
        [$from, $to] = self::weekRange($weekEnding);
        $we = $to->format('Ymd');
        $errors = [];
        $warnings = [];

        // ---- header settings ----
        $did = (string) $s['msa_distributor_id'];
        if (strlen($did) !== 8) {
            $errors[] = 'Settings: MSA distributor ID must be exactly 8 characters (the ID assigned by MSA, entered in MSA settings on this page).';
        }
        foreach (['msa_name' => 'distributor name', 'msa_address' => 'address', 'msa_city' => 'city', 'msa_state' => 'state', 'msa_zip' => 'ZIP',
            'msa_contact_first' => 'contact first name', 'msa_contact_last' => 'contact last name'] as $k => $label) {
            if (blank($s[$k])) {
                $errors[] = "Settings: $label is required.";
            }
        }
        if (strlen(Writer::digits($s['msa_phone'])) !== 10) {
            $errors[] = 'Settings: contact phone must be 10 digits.';
        }
        if ($to->dayOfWeek !== MsaSettings::weekEndDay()) {
            $errors[] = "Week ending $weekEnding is a {$to->englishDayOfWeek}; the MSA week ends on ".MsaSettings::DAYS[MsaSettings::weekEndDay()].'.';
        }
        if ($to->isFuture() || $to->isToday()) {
            $warnings[] = 'The reporting week has not ended yet; figures may change.';
        }

        // ---- facts: shipments (+) and customer returns (-), quantities converted to MSA selling units ----
        $ship = DB::table('sales_order_lines as l')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->whereIn('o.status', ['delivered', 'invoiced'])
            ->whereRaw('COALESCE(o.delivered_at, o.invoiced_at) between ? and ?', [$from, $to])
            ->select('o.ship_to_id', 'o.customer_id', 'o.number as doc', 'o.sales_rep_id', 'l.product_id', 'l.qty', 'l.line_total',
                DB::raw('COALESCE(o.delivered_at, o.invoiced_at) as at'))->get()->all();
        $ret = DB::table('credit_memo_lines as l')->join('credit_memos as m', 'm.id', '=', 'l.credit_memo_id')->join('sales_orders as o', 'o.id', '=', 'm.sales_order_id')
            ->where('m.status', 'posted')->whereDate('m.memo_date', '>=', $from->toDateString())->whereDate('m.memo_date', '<=', $to->toDateString())
            ->select('o.ship_to_id', 'm.customer_id', 'm.number as doc', 'o.sales_rep_id', 'l.product_id', 'l.qty', 'l.line_total', 'l.disposition', 'm.memo_date as at')->get()->all();

        $pids = array_unique(array_merge(array_column($ship, 'product_id'), array_column($ret, 'product_id')));
        $reportable = Product::query()->whereNotNull('msa_category_code')->where('msa_exclude', false)->get()->keyBy('id');
        $active = Product::whereIn('id', $pids)->get()->keyBy('id');
        foreach ($active as $p) {
            if (! $reportable->has($p->id) && ! $p->msa_exclude && in_array($p->tax_class?->value ?? $p->tax_class, self::TOBACCO_CLASSES, true)) {
                $warnings[] = "Product {$p->sku} ({$p->name}) is tobacco but has no MSA category code: its sales are NOT in the file.";
            }
        }

        // ---- closing inventory at week end (selling units): on hand now minus movements after the week ---
        $onHand = DB::table('inventories')->select('product_id', DB::raw('SUM(qty) as q'))->groupBy('product_id')->pluck('q', 'product_id');
        $after = DB::table('stock_movements')->where('created_at', '>', $to)->select('product_id', DB::raw('SUM(qty) as q'))->groupBy('product_id')->pluck('q', 'product_id');
        $floor = DB::table('stock_movements')->where('type', 'vendor_return')->whereBetween('created_at', [$from, $to])
            ->select('product_id', DB::raw('SUM(qty) as q'))->groupBy('product_id')->pluck('q', 'product_id');

        $factor = fn (Product $p) => BigDecimal::of((string) ($p->msa_units_per_sale ?: '1'));
        $bid = [];
        foreach ($reportable as $p) {
            $inv = BigDecimal::of((string) ($onHand[$p->id] ?? '0'))->minus(BigDecimal::of((string) ($after[$p->id] ?? '0')));
            $hasActivity = in_array($p->id, $pids) || ! $inv->isZero() || isset($floor[$p->id]);
            if (! $hasActivity) {
                continue;
            }
            if ($inv->isNegative()) {
                $warnings[] = "Product {$p->sku}: computed closing inventory is negative ({$inv}); reported as 0.";
                $inv = BigDecimal::zero();
            }
            foreach (self::productProblems($p) as $pr) {
                $errors[] = "Product {$p->sku} ({$p->name}): $pr.";
            }
            $bid[$p->id] = ['p' => $p, 'inv' => $inv->multipliedBy($factor($p)),
                'floor' => BigDecimal::of((string) ($floor[$p->id] ?? '0'))->abs()->negated()->multipliedBy($factor($p))];
        }
        if ($floor->isNotEmpty() && ! $altria) {
            $warnings[] = 'Vendor (floor) returns exist this week but Altria fields are off, so they are not reported.';
        }

        // ---- PUR aggregation ----
        $pur = [];
        $shipTos = [];
        $add = function ($r, string $kind) use (&$pur, &$shipTos, $reportable, $factor, $altria) {
            $p = $reportable->get($r->product_id);
            if (! $p) {
                return;
            }
            $qty = BigDecimal::of((string) $r->qty)->multipliedBy($factor($p));
            $usd = BigDecimal::of((string) $r->line_total);
            if ($kind !== 'ship') {
                $qty = $qty->abs()->negated();
                $usd = $usd->abs()->negated();
            }
            $date = CarbonImmutable::parse($r->at)->format('Ymd');
            $key = implode('|', [$r->ship_to_id, $p->id, $kind, $altria ? $date : '']);
            $pur[$key] ??= ['ship_to_id' => $r->ship_to_id, 'product_id' => $p->id, 'kind' => $kind, 'date' => $altria ? $date : null,
                'qty' => BigDecimal::zero(), 'usd' => BigDecimal::zero(), 'docs' => []];
            $pur[$key]['qty'] = $pur[$key]['qty']->plus($qty);
            $pur[$key]['usd'] = $pur[$key]['usd']->plus($usd);
            $pur[$key]['docs'][$r->doc] = true;
            $shipTos[$r->ship_to_id] = $r->sales_rep_id;
        };
        foreach ($ship as $r) {
            $add($r, 'ship');
        }
        foreach ($ret as $r) {
            $add($r, ($r->disposition ?? 'restock') === 'damage' ? 'unsaleable' : 'saleable');
        }

        // ---- SID ----
        $sids = [];
        $seen = [];
        foreach (ShipTo::with('customer')->whereIn('id', array_keys($shipTos))->get() as $st) {
            $c = $st->customer;
            $cn = Writer::ascii((string) $c->code);
            $sn = self::shippingNumber($st);
            $label = "Ship-to {$st->name} ({$c->code})";
            if (strlen($cn) > 8) {
                $errors[] = "$label: customer code longer than 8 characters (MSA customer number).";
            }
            if (strlen($sn) > 8) {
                $errors[] = "$label: MSA shipping number longer than 8 characters.";
            }
            $k = strtoupper($cn.'|'.$sn.'|'.$st->msa_shipping_ext);
            if (isset($seen[$k])) {
                $errors[] = "$label: customer/shipping number $cn/$sn is not unique.";
            }
            $seen[$k] = true;
            $cash = (bool) $st->msa_cash_and_carry;
            $addr = $cash && blank($st->address) ? $s['msa_address'] : $st->address;
            $city = $cash && blank($st->city) ? $s['msa_city'] : $st->city;
            $state = $cash && blank($st->state) ? $s['msa_state'] : $st->state;
            $zip = Writer::digits($cash && blank($st->zip) ? $s['msa_zip'] : $st->zip);
            if (blank($addr) || blank($city) || blank($state)) {
                $errors[] = "$label: street, city and state are required.";
            }
            if (! in_array(strlen($zip), [5, 9], true)) {
                $errors[] = "$label: ZIP must be 5 or 9 digits.";
            }
            if (preg_match('/\b(P\.?\s*O\.?\s*BOX)\b/i', (string) $addr)) {
                $warnings[] = "$label: address is a PO box (MSA asks for the street address).";
            }
            $phone = Writer::digits($st->phone ?: $c->phone);
            $sids[$st->id] = ['st' => $st, 'c' => $c, 'cn' => $cn, 'sn' => $sn, 'values' => [
                'type' => 'SID', 'customer_number' => $cn, 'shipping_number' => $sn, 'shipping_ext' => $st->msa_shipping_ext,
                'name' => $st->name ?: $c->name, 'store_number' => $st->msa_store_number, 'address' => $addr, 'city' => $city,
                'state' => strtoupper((string) $state), 'zip' => $zip, 'country' => 'USA',
                'tax_jurisdiction' => $taxJur === 'sid' ? strtoupper((string) $state) : null,
                'phone' => strlen($phone) === 10 ? $phone : null, 'class_of_trade' => self::classOfTrade($c),
                'tdlinx' => $st->msa_tdlinx, 'cash_and_carry' => $cash ? 'Y' : 'N', 'bill_to_number' => $cn, 'bill_to_name' => $c->name,
                'promo_acceptance' => $c->msa_promo_acceptance ? 'Y' : 'N', 'sales_rep' => $shipTos[$st->id] ? (string) $shipTos[$st->id] : null,
            ]];
        }

        // ---- write records ----
        $lines = [];
        $bidMeasures = $altria ? 2 : 1;
        $purCodes = array_values(array_filter([Layout::MEASURE_QTY, $dollars ? Layout::MEASURE_DOLLARS : null,
            $altria ? Layout::MEASURE_SALEABLE_RETURNS : null, $altria ? Layout::MEASURE_UNSALEABLE_RETURNS : null]));
        $lines[] = Writer::record(Layout::HID, [
            'type' => 'HID', 'distributor_id' => $did, 'project' => 'TOB', 'test' => $test ? 'T' : null, 'interval' => 'W', 'week_ending' => $we,
            'name' => $s['msa_name'], 'address' => $s['msa_address'], 'city' => $s['msa_city'], 'state' => strtoupper((string) $s['msa_state']),
            'zip' => Writer::digits($s['msa_zip']), 'country' => 'USA', 'contact_last' => $s['msa_contact_last'], 'contact_first' => $s['msa_contact_first'],
            'phone' => Writer::digits($s['msa_phone']), 'fax' => strlen(Writer::digits($s['msa_fax'])) === 10 ? Writer::digits($s['msa_fax']) : null,
            'email' => $s['msa_email'], 'bid_measures' => (string) $bidMeasures, 'sid_measures' => '0', 'pur_measures' => (string) count($purCodes),
            'created' => $created, 'inventory_resubmission' => ! empty($opts['resubmit_inventory']) ? '1' : null,
        ], Layout::HID_LENGTH);

        $tInv = BigDecimal::zero();
        $tFloor = BigDecimal::zero();
        $preview = ['bid' => [], 'sid' => [], 'pur' => []];
        foreach ($bid as $b) {
            $p = $b['p'];
            $tInv = $tInv->plus($b['inv']);
            $tFloor = $tFloor->plus($b['floor']);
            $v = ['type' => 'BID', 'upc' => Writer::digits($p->msa_upc ?: $p->upc), 'sku' => Writer::ascii((string) $p->sku),
                'description' => $p->msa_description ?: $p->name, 'promo_description' => $p->msa_promo ? $p->msa_promo_description : null,
                'items_per_unit' => (string) (int) $p->msa_items_per_unit, 'promo_indicator' => $p->msa_promo ? 'Y' : 'N',
                'msa_category' => $p->msa_category_code, 'unit_size' => $p->msa_unit_size !== null ? (string) (int) round((float) $p->msa_unit_size) : null,
                'unit_size_desc' => $p->msa_unit_size_desc, 'shipper_flag' => $p->msa_shipper_flag, 'mfr_promo_code' => $p->msa_promo ? $p->msa_promo_code : null,
                'mfr_product_code' => $p->msa_mfr_product_code, 'm1_code' => Layout::MEASURE_INVENTORY, 'm1_value' => (string) $b['inv'],
                'm2_code' => $altria ? Layout::MEASURE_FLOOR_RETURNS : null, 'm2_value' => $altria ? (string) $b['floor'] : null];
            if ($this->safe($lines, fn () => Writer::record(Layout::BID, $v, $altria ? Layout::BID_LENGTH_ALTRIA : Layout::BID_LENGTH), "Product {$p->sku}", $errors)) {
                $preview['bid'][] = [$p->sku, $v['upc'], $v['description'], $v['items_per_unit'], $p->msa_category_code, Writer::number($b['inv'])];
            }
        }
        foreach ($sids as $x) {
            if ($this->safe($lines, fn () => Writer::record(Layout::SID, $x['values'], Layout::SID_LENGTH), "Ship-to {$x['st']->name}", $errors)) {
                $v = $x['values'];
                $preview['sid'][] = [$x['cn'], $x['sn'], $v['name'], trim($v['address'].', '.$v['city'].' '.$v['state'].' '.$v['zip']), $v['class_of_trade']];
            }
        }
        ksort($pur);
        $tQty = BigDecimal::zero();
        $tUsd = BigDecimal::zero();
        $tSal = BigDecimal::zero();
        $tUns = BigDecimal::zero();
        $nPur = 0;
        foreach ($pur as $r) {
            $x = $sids[$r['ship_to_id']] ?? null;
            $p = $reportable->get($r['product_id']);
            if (! $x || ! $p) {
                continue;
            }
            $sal = $r['kind'] === 'saleable' ? $r['qty'] : BigDecimal::zero();
            $uns = $r['kind'] === 'unsaleable' ? $r['qty'] : BigDecimal::zero();
            $measures = [[Layout::MEASURE_QTY, (string) $r['qty'], 'N']];
            if ($dollars) {
                $measures[] = [Layout::MEASURE_DOLLARS, (string) $r['usd']->toScale(2, RoundingMode::HALF_UP), 'NB'];
            }
            if ($altria) {
                $measures[] = [Layout::MEASURE_SALEABLE_RETURNS, (string) $sal, 'N'];
                $measures[] = [Layout::MEASURE_UNSALEABLE_RETURNS, (string) $uns, 'N'];
            }
            $docs = array_keys($r['docs']);
            $v = ['type' => 'PUR', 'customer_number' => $x['cn'], 'shipping_number' => $x['sn'], 'shipping_ext' => $x['st']->msa_shipping_ext,
                'sku' => Writer::ascii((string) $p->sku), 'invoice' => count($docs) === 1 ? $docs[0] : null, 'transaction_date' => $r['date']];
            if ($this->safe($lines, fn () => self::purRecord($v, $measures), "Sale of {$p->sku}", $errors)) {
                $nPur++;
                $tQty = $tQty->plus($r['qty']);
                $tUsd = $tUsd->plus($r['usd']);
                $tSal = $tSal->plus($sal);
                $tUns = $tUns->plus($uns);
                $preview['pur'][] = [$x['cn'], $x['sn'], $p->sku, $r['date'] ?? '', $r['kind'], Writer::number($r['qty']), $dollars ? (string) $r['usd']->toScale(2) : ''];
            }
        }
        $tot = ['type' => 'TOT', 'distributor_id' => $did, 'week_ending' => $we, 'bid_count' => (string) count($preview['bid']),
            'sid_count' => (string) count($preview['sid']), 'pur_count' => (string) $nPur,
            'm1_code' => Layout::MEASURE_QTY, 'm1_value' => (string) $tQty,
            'm2_code' => $dollars ? Layout::MEASURE_DOLLARS : null, 'm2_value' => $dollars ? (string) $tUsd->toScale(2) : null,
            'm3_code' => Layout::MEASURE_INVENTORY, 'm3_value' => (string) $tInv];
        if ($altria) {
            $tot += ['m4_code' => Layout::MEASURE_FLOOR_RETURNS, 'm4_value' => (string) $tFloor, 'm5_code' => Layout::MEASURE_SALEABLE_RETURNS,
                'm5_value' => (string) $tSal, 'm6_code' => Layout::MEASURE_UNSALEABLE_RETURNS, 'm6_value' => (string) $tUns];
        }
        $this->safe($lines, fn () => Writer::record(Layout::TOT, $tot, $altria ? Layout::TOT_LENGTH_ALTRIA : Layout::TOT_LENGTH), 'Totals', $errors);

        if (! $bid) {
            $warnings[] = 'No MSA-reportable products (set an MSA category code on tobacco products).';
        }
        $eol = ($s['msa_line_ending'] ?? 'crlf') === 'lf' ? "\n" : "\r\n";

        return [
            'week_ending' => $to->toDateString(), 'file_name' => self::fileName($weekEnding), 'test' => $test, 'altria' => $altria,
            'lines' => $lines, 'content' => implode($eol, $lines).$eol, 'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)), 'preview' => $preview,
            'totals' => ['bid' => count($preview['bid']), 'sid' => count($preview['sid']), 'pur' => $nPur, 'qty' => Writer::number($tQty),
                'dollars' => $dollars ? (string) $tUsd->toScale(2) : null, 'inventory' => Writer::number($tInv)],
        ];
    }

    /** PUR: fixed part + consecutive 14-char measures (3 code + 11 value) from column 103 (Altria: shift left if no dollars). */
    public static function purRecord(array $values, array $measures): string
    {
        $len = Layout::PUR_MEASURES_START - 1 + 14 * count($measures);
        $line = Writer::record(Layout::PUR, $values, $len);
        $col = Layout::PUR_MEASURES_START;
        foreach ($measures as [$code, $val, $just]) {
            $line = substr_replace($line, $code.Writer::field($val, 11, $just, "measure $code"), $col - 1, 14);
            $col += 14;
        }

        return $line;
    }

    private function safe(array &$lines, callable $fn, string $label, array &$errors): bool
    {
        try {
            $lines[] = $fn();

            return true;
        } catch (\LengthException $e) {
            $errors[] = "$label: ".$e->getMessage();

            return false;
        }
    }

    /** Generate and archive. Refuses when pre-checks report errors. */
    public function generate(string $weekEnding, ?int $userId = null, array $opts = []): MsaSubmission
    {
        $r = $this->build($weekEnding, $opts);
        if ($r['errors']) {
            throw new \RuntimeException('MSA file not generated: '.count($r['errors']).' error(s). '.$r['errors'][0]);
        }
        $dir = 'msa/'.CarbonImmutable::parse($r['week_ending'])->format('Ymd').'/'.now()->format('YmdHis').($r['test'] ? '-T' : '-L');
        $path = $dir.'/'.$r['file_name'];
        Storage::disk('local')->put($path, $r['content']);

        return MsaSubmission::create([
            'week_ending' => $r['week_ending'], 'test' => $r['test'], 'altria' => $r['altria'], 'file_name' => $r['file_name'], 'path' => $path,
            'sha256' => hash('sha256', $r['content']), 'bid_count' => $r['totals']['bid'], 'sid_count' => $r['totals']['sid'],
            'pur_count' => $r['totals']['pur'], 'qty_total' => $r['totals']['qty'], 'dollars_total' => $r['totals']['dollars'],
            'inventory_total' => $r['totals']['inventory'], 'warnings' => $r['warnings'], 'user_id' => $userId,
        ]);
    }
}
