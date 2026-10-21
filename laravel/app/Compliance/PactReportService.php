<?php

namespace App\Compliance;

use App\Exceptions\WorkflowException;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Package;
use App\Models\PactRegistration;
use App\Models\PactReport;
use App\Models\PactReportLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly PACT Act report per state (15 U.S.C. 376(a)(2)): every shipment of cigarettes (incl. RYO and ENDS) and smokeless tobacco
 * into the state during the month, due by the 10th of the following month, with recipient name + address, brand, quantity and the
 * name / address / phone of the person delivering, organised by city/town and ZIP. Only shipments into a state other than the
 * shipping (home) state are interstate. A filed report is frozen.
 */
class PactReportService
{
    public static function homeState(): string
    {
        return strtoupper(config('erp.home_state', 'KY'));
    }

    public function generate(string $state, string $period): PactReport
    {
        $state = strtoupper(trim($state));
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new WorkflowException('Period must be YYYY-MM.');
        }
        if ($state === self::homeState()) {
            throw new WorkflowException("{$state} is the shipping state; in-state deliveries are not interstate PACT shipments.");
        }
        $from = CarbonImmutable::parse($period.'-01')->startOfDay();
        $to = $from->endOfMonth();
        $rep = PactReport::firstOrNew(['state' => $state, 'period' => $period]);
        if ($rep->status === 'filed') {
            throw new WorkflowException("The {$state} {$period} report is already filed and locked.");
        }

        return DB::transaction(function () use ($rep, $state, $from, $to) {
            $rep->fill(['due_on' => $to->addDays(10)->toDateString(), 'status' => 'draft', 'generated_at' => now()])->save();
            $rep->lines()->delete();
            $own = Carrier::where('code', 'OWN')->first();
            $company = Company::current();
            $orders = SalesOrder::with('lines.product', 'customer.licenses', 'shipTo')->whereNotNull('delivered_at')
                ->whereBetween('delivered_at', [$from, $to])->whereHas('shipTo', fn ($q) => $q->where('state', $state))->get();
            $totals = [];
            foreach ($orders as $so) {
                $pkgs = Package::with('carrier')->whereHas('pickList', fn ($q) => $q->where('sales_order_id', $so->id))->orderBy('seq')->get();
                $car = $pkgs->firstWhere('carrier_id', '!=', null)?->carrier ?? $own;
                $lic = $so->customer->licenses->first(fn ($l) => strtoupper($l->state) === $state && (! $l->ship_to_id || $l->ship_to_id === $so->ship_to_id));
                foreach ($so->lines as $l) {
                    $cat = PactCategory::of($l->product);
                    if (! $cat) {
                        continue;
                    }
                    $per = $l->product->msa_items_per_unit;
                    PactReportLine::create(['pact_report_id' => $rep->id, 'sales_order_id' => $so->id, 'shipped_on' => $so->delivered_at->toDateString(),
                        'invoice' => $so->number, 'customer' => $so->customer->name.($so->shipTo->name && $so->shipTo->name !== $so->customer->name ? ' / '.$so->shipTo->name : ''),
                        'license' => $lic?->number, 'address' => $so->shipTo->address, 'city' => $so->shipTo->city, 'zip' => $so->shipTo->zip,
                        'category' => $cat, 'brand' => MsaDirectory::brandOf($l->product) ?: null, 'sku' => $l->product->sku,
                        'description' => mb_substr((string) $l->product->name, 0, 250), 'qty' => Money::s($l->qty), 'unit' => $l->product->base_unit_name ?? 'Each',
                        'sticks_or_units' => $per ? Money::mul($l->qty, $per) : null,
                        'carrier' => $car?->name ?? ($company->name.' (own delivery)'), 'carrier_address' => $car?->address, 'carrier_phone' => $car?->phone,
                        'tracking' => $pkgs->pluck('tracking_number')->filter()->unique()->implode(', ') ?: null]);
                    $totals[$cat] = Money::add($totals[$cat] ?? 0, $l->qty);
                }
            }
            $rep->update(['totals' => $totals]);

            return $rep->fresh();
        });
    }

    /** Lines in the statutory order: city/town, then ZIP, then recipient, date, invoice. */
    public function lines(PactReport $r)
    {
        return $r->lines()->get()->sortBy(fn ($l) => [mb_strtoupper((string) $l->city), (string) $l->zip, $l->customer, $l->shipped_on->toDateString(), $l->invoice, $l->sku])->values();
    }

    public function markFiled(PactReport $r, User $by, ?string $reference): PactReport
    {
        if (! $by->isAdmin()) {
            throw new WorkflowException('Only an owner or admin can mark a PACT report filed.');
        }
        if ($r->status === 'filed') {
            throw new WorkflowException('Already filed.');
        }
        $r->update(['status' => 'filed', 'filed_by' => $by->id, 'filed_at' => now(), 'filed_reference' => $reference ? mb_substr($reference, 0, 190) : null]);

        return $r;
    }

    /** States with covered interstate deliveries in a month (to know which reports are needed). */
    public function statesWithShipments(string $period): array
    {
        $from = CarbonImmutable::parse($period.'-01')->startOfDay();

        return SalesOrder::whereNotNull('delivered_at')->whereBetween('delivered_at', [$from, $from->endOfMonth()])
            ->with('lines.product', 'shipTo')->get()
            ->filter(fn ($so) => $so->shipTo && strtoupper($so->shipTo->state) !== self::homeState() && $so->lines->contains(fn ($l) => PactCategory::of($l->product)))
            ->map(fn ($so) => strtoupper($so->shipTo->state))->unique()->sort()->values()->all();
    }

    public function csv(PactReport $r): string
    {
        $h = fopen('php://temp', 'w+');
        fputcsv($h, ['City/Town', 'ZIP', 'Recipient', 'License', 'Address', 'Ship date', 'Invoice', 'Category', 'Brand', 'SKU', 'Description', 'Quantity', 'Unit', 'Sticks/units',
            'Delivered by', 'Deliverer address', 'Deliverer phone', 'Tracking']);
        foreach ($this->lines($r) as $l) {
            fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, [
                $l->city, $l->zip, $l->customer, $l->license, $l->address, $l->shipped_on->toDateString(), $l->invoice, PactCategory::LABELS[$l->category] ?? $l->category,
                $l->brand, $l->sku, $l->description, rtrim(rtrim((string) $l->qty, '0'), '.'), $l->unit, $l->sticks_or_units !== null ? rtrim(rtrim((string) $l->sticks_or_units, '0'), '.') : '',
                $l->carrier, $l->carrier_address, $l->carrier_phone, $l->tracking]));
        }
        rewind($h);

        return stream_get_contents($h);
    }

    public static function registration(string $jurisdiction): ?PactRegistration
    {
        return PactRegistration::where('jurisdiction', strtoupper($jurisdiction))->where('active', true)->latest('filed_on')->first();
    }
}
