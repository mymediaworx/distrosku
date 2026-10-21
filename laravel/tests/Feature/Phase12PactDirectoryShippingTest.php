<?php

namespace Tests\Feature;

use App\Compliance\MsaDirectory;
use App\Compliance\PactReportService;
use App\Exceptions\WorkflowException;
use App\Livewire\Warehouse;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\MsaDirectoryEntry;
use App\Models\PactRegistration;
use App\Models\PactReport;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Warehouse as WH;
use App\Services\ComplianceChecker;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Warehouse\ShippingService;
use App\Warehouse\WarehouseService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class Phase12PactDirectoryShippingTest extends TestCase
{
    use RefreshDatabase;

    private WH $wh;

    private User $admin;

    private User $picker;

    private Product $cig;

    private Product $snus;

    private Customer $cust;

    private ShipTo $in;

    private ShipTo $ky;

    private const CSV = "Manufacturer,Brand Family,Product Type\nPhilip Morris USA,Marlboro,cigarette\nTop Tobacco,Top,ryo\n\"Acme, Inc.\",Acme Gold,both\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = WH::where('code', 'MAIN')->first();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->picker = User::factory()->create(['role' => 'warehouse']);
        $this->cig = Product::factory()->create(['sku' => 'CIG-1', 'brand' => 'Marlboro', 'tax_class' => 'cigarette_stamped', 'pact_covered' => true, 'price' => 70, 'msa_items_per_unit' => 200]);
        $this->snus = Product::factory()->create(['sku' => 'SN-1', 'brand' => 'Grizzly', 'tax_class' => 'snuff_chew', 'pact_covered' => true, 'price' => 30]);
        foreach ([$this->cig, $this->snus] as $p) {
            app(InventoryService::class)->move($p, $this->wh, 100, 'receipt');
        }
        $this->cust = Customer::factory()->create(['name' => 'Hoosier Mart']);
        $this->in = ShipTo::factory()->create(['customer_id' => $this->cust->id, 'state' => 'IN', 'city' => 'Evansville', 'zip' => '47708', 'address' => '1 Main St']);
        $this->ky = ShipTo::factory()->create(['customer_id' => $this->cust->id, 'state' => 'KY']);
        foreach (['tobacco_retail', 'tobacco_wholesale'] as $t) {
            \App\Models\CustomerLicense::create(['customer_id' => $this->cust->id, 'type' => $t, 'number' => 'KY-'.$t, 'state' => 'KY', 'expires_at' => now()->addYear()]);
        }
    }

    private function enablePact(): void
    {
        Setting::put('pact_interstate_enabled', '1');
        PactRegistration::create(['jurisdiction' => 'US', 'legal_name' => 'Vega Distribution LLC', 'filed_on' => now()->subYear(), 'reference' => 'ATF-123']);
        PactRegistration::create(['jurisdiction' => 'IN', 'legal_name' => 'Vega Distribution LLC', 'filed_on' => now()->subYear(), 'reference' => 'IN-9',
            'agent_name' => 'CT Corp', 'administrator' => 'Indiana Dept of Revenue']);
    }

    private function order(ShipTo $st, array $lines): SalesOrder
    {
        $so = SalesOrder::create(['number' => 'SO-P'.uniqid(), 'customer_id' => $this->cust->id, 'ship_to_id' => $st->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        foreach ($lines as [$p, $q]) {
            $so->lines()->create(['product_id' => $p->id, 'qty' => $q, 'unit_price' => $p->price]);
        }

        return $so->fresh();
    }

    public function test_pact_interstate_block_kept_by_default_and_needs_registrations(): void
    {
        $chk = app(ComplianceChecker::class);
        $this->assertStringContainsString('PACT', implode(' ', $chk->checkLine($this->cig, $this->cust, $this->in)));
        Setting::put('pact_interstate_enabled', '1');
        $this->assertStringContainsString('registration', implode(' ', $chk->checkLine($this->snus, $this->cust, $this->in)));
        $this->enablePact();
        $this->assertSame([], $chk->checkLine($this->snus, $this->cust, $this->in));
        $this->assertSame([], $chk->checkLine($this->cig, $this->cust, $this->ky));
        PactRegistration::where('jurisdiction', 'IN')->update(['expires_on' => now()->subDay()]);
        $this->assertNotEmpty($chk->checkLine($this->snus, $this->cust, $this->in));
    }

    public function test_msa_directory_import_enforced_with_effective_dates(): void
    {
        $this->enablePact();
        $dir = app(MsaDirectory::class);
        $chk = app(ComplianceChecker::class);
        $this->assertSame([], $chk->checkLine($this->cig, $this->cust, $this->in)); // no list loaded, not strict
        Setting::put('msa_directory_strict', '1');
        $this->assertStringContainsString('No IN MSA directory', implode(' ', $chk->checkLine($this->cig, $this->cust, $this->in)));

        $imp = $dir->import('in', self::CSV, now()->subMonth()->toDateString(), $this->admin, 'in.csv', 'https://example.test/in');
        $this->assertSame(4, $imp->rows); // Acme Gold both -> 2
        $this->assertSame([], $chk->checkLine($this->cig, $this->cust, $this->in));
        $this->cig->update(['brand' => 'Nobrand']);
        $this->assertStringContainsString('not on the IN MSA directory', implode(' ', $chk->checkLine($this->cig->fresh(), $this->cust, $this->in)));
        $this->cig->update(['msa_brand_family' => 'acme  gold']);
        $this->assertSame([], $chk->checkLine($this->cig->fresh(), $this->cust, $this->in));
        // snuff is not a directory product
        $this->assertSame([], $chk->checkLine($this->snus, $this->cust, $this->in));

        // a new list from next week drops Acme: old list still applies today, new one after
        $dir->import('IN', "manufacturer,brand_family,product_type\nPhilip Morris USA,Marlboro,cigarette\n", now()->addWeek()->toDateString(), $this->admin);
        $this->assertSame([], $chk->checkLine($this->cig->fresh(), $this->cust, $this->in));
        $this->assertNotEmpty($chk->checkLine($this->cig->fresh(), $this->cust, $this->in, now()->addWeeks(2)));
        $this->assertEquals(now()->addWeek()->subDay()->toDateString(), MsaDirectoryEntry::where('brand_family', 'Acme Gold')->first()->effective_to->toDateString());

        // confirm blocks a non-listed brand
        $this->cig->update(['msa_brand_family' => null, 'brand' => 'Nobrand']);
        $this->expectException(\App\Exceptions\ComplianceException::class);
        app(SalesOrderService::class)->confirm($this->order($this->in, [[$this->cig->fresh(), 1]]));
    }

    public function test_directory_csv_errors(): void
    {
        $dir = app(MsaDirectory::class);
        try {
            $dir->import('IN', "manufacturer,brand_family,product_type\nX,Y,cigar\n", now()->toDateString());
            $this->fail('bad type accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Row 2', $e->getMessage());
        }
        $this->expectException(WorkflowException::class);
        $dir->import('Indiana', self::CSV, now()->toDateString());
    }

    public function test_monthly_pact_report_by_city_zip_with_carrier_and_lock(): void
    {
        $this->enablePact();
        $svc = app(SalesOrderService::class);
        $wh = app(WarehouseService::class);
        $so = $svc->confirm($this->order($this->in, [[$this->cig, 2], [$this->snus, 3]]));
        $pl = $wh->pickList($so);
        $wh->scanPick($pl, 'CIG-1', 2);
        $wh->scanPick($pl, 'SN-1', 3);
        $wh->completePick($pl);
        $wh->scanPack($pl->fresh(), 'CIG-1', 2);
        $wh->scanPack($pl->fresh(), 'SN-1', 3);
        $pkg = $wh->closePackage($pl->fresh(), 12);
        $ups = Carrier::where('code', 'UPS')->first();
        $ups->update(['address' => 'Atlanta GA', 'phone' => '800-742-5877']);
        app(ShippingService::class)->assign($pkg, $ups->id, '1Z999AA10123456784', 'Ground');
        $wh->completePack($pl->fresh());
        $svc->deliver($so->fresh());
        $so2 = $svc->confirm($this->order(ShipTo::factory()->create(['customer_id' => $this->cust->id, 'state' => 'IN', 'city' => 'Bloomington', 'zip' => '47401']), [[$this->snus, 1]]));
        $svc->deliver($so2);
        $svc->deliver($svc->confirm($this->order($this->ky, [[$this->snus, 1]]))); // in-state: excluded

        $period = now()->format('Y-m');
        $rs = app(PactReportService::class);
        $this->assertSame(['IN'], $rs->statesWithShipments($period));
        $rep = $rs->generate('IN', $period);
        $this->assertSame(3, $rep->lines()->count());
        $this->assertEquals(now()->endOfMonth()->addDays(10)->toDateString(), $rep->due_on->toDateString());
        $lines = $rs->lines($rep);
        $this->assertSame('Bloomington', $lines[0]->city); // city order
        $cig = $rep->lines()->where('sku', 'CIG-1')->first();
        $this->assertSame('cigarette', $cig->category);
        $this->assertSame('Marlboro', $cig->brand);
        $this->assertEquals(400, (float) $cig->sticks_or_units);
        $this->assertSame('UPS', $cig->carrier);
        $this->assertSame('800-742-5877', $cig->carrier_phone);
        $this->assertSame('1Z999AA10123456784', $cig->tracking);
        $this->assertSame('Own delivery (company truck)', $rep->lines()->where('sales_order_id', $so2->id)->value('carrier'));
        $this->assertEquals(4, (float) $rep->totals['smokeless']);

        $this->actingAs($this->admin);
        $html = $this->get(route('pact.report', $rep))->assertOk()->assertSee('Evansville')->assertSee('ATF-123')->assertSee('376(a)(2)')->getContent();
        $this->assertStringNotContainsStringIgnoringCase('powered by', $html);
        $csv = $this->get(route('pact.report', ['report' => $rep, 'format' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('1Z999AA10123456784', $csv);
        $this->assertStringStartsWith('%PDF', $this->get(route('pact.report', ['report' => $rep, 'format' => 'pdf']))->getContent());
        $this->get(route('pact.registration', PactRegistration::first()))->assertOk()->assertSee('5070.1');
        $this->get('/admin/pact-reports')->assertOk()->assertSee('IN');
        $this->get('/admin/pact-registrations')->assertOk();

        $rs->markFiled($rep, $this->admin, 'CONF-1');
        $this->expectException(WorkflowException::class);
        $rs->generate('IN', $period);
    }

    public function test_home_state_report_refused_and_non_admin_forbidden(): void
    {
        try {
            app(PactReportService::class)->generate('KY', '2026-09');
            $this->fail('home state accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('in-state', $e->getMessage());
        }
        $rep = app(PactReportService::class)->generate('OH', '2026-09');
        $this->actingAs($this->picker)->get(route('pact.report', $rep))->assertForbidden();
        $this->assertSame(1, PactReport::count());
    }

    public function test_tracking_label_and_postal_block(): void
    {
        $so = app(SalesOrderService::class)->confirm($this->order($this->ky, [[$this->snus, 2]]));
        $wh = app(WarehouseService::class);
        $pl = $wh->pickList($so);
        $wh->scanPick($pl, 'SN-1', 2);
        $wh->completePick($pl);
        $this->actingAs($this->picker);
        $usps = Carrier::where('code', 'USPS')->first();
        $fedex = Carrier::where('code', 'FEDEX')->first();
        $c = Livewire::test(Warehouse\Pack::class, ['so' => $so->fresh()])->set('scan', 'SN-1')->set('qty', '2')->call('add')
            ->set('carrierId', (string) $usps->id)->set('tracking', '9400100000000000000000')->call('closeCarton')->assertSee('nonmailable');
        $c->set('carrierId', (string) $fedex->id)->set('tracking', '123456789012')->call('closeCarton')->assertSee('FedEx 123456789012');
        $pkg = $pl->packages()->first();
        $this->assertSame('https://www.fedex.com/fedextrack/?trknbr=123456789012', $pkg->trackingUrl());
        $this->assertNotNull($pkg->shipped_at);
        $html = $this->get(route('wh.print.label', $pkg))->assertOk()->assertSee('123456789012')->assertSee('FedEx')->getContent();
        $this->assertStringNotContainsStringIgnoringCase('powered by', $html);
        $this->assertStringStartsWith('%PDF', $this->get(route('wh.print.label', ['package' => $pkg, 'format' => 'pdf']))->getContent());
        $this->assertNotNull($pkg->fresh()->label_printed_at);
        try {
            app(ShippingService::class)->assign($pkg, $fedex->id, '<script>');
            $this->fail('bad tracking accepted');
        } catch (WorkflowException) {
        }
        $this->get('/admin/shipments')->assertOk()->assertSee('123456789012');
    }

    public function test_admin_pages_and_csv_upload(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']))->get('/admin/settings')->assertOk()->assertSee('PACT');
        $this->actingAs($this->admin);
        $this->get('/admin/msa-directory-imports')->assertOk();
        $this->get('/admin/carriers')->assertOk()->assertSee('UPS');
        $this->get('/admin/products/'.$this->cig->id.'/edit')->assertOk()->assertSee('MSA directory brand family');
        Livewire::test(\App\Filament\Resources\MsaDirectoryImportResource\Pages\ListMsaDirectoryImports::class)
            ->callAction('import', ['state' => 'OH', 'effective_on' => now()->toDateString(), 'file' => UploadedFile::fake()->createWithContent('oh.csv', self::CSV)])
            ->assertHasNoActionErrors();
        $this->assertSame(4, MsaDirectoryEntry::where('state', 'OH')->count());
    }
}
