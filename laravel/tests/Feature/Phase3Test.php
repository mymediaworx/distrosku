<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Pages\KySubjobberReport;
use App\Filament\Pages\KyTobaccoDistributorReport;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\TaxFiling;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Reports\KyCigaretteXml;
use App\Reports\KyTobaccoXml;
use App\Reports\TaxReport;
use App\Services\CreditMemoService;
use App\Services\PostingService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase3Test extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = Warehouse::where('code', 'MAIN')->first();
        foreach (['ky_filer_fein' => '12-3456789', 'ky_filer_license' => 'KY123456', 'ky_filer_name' => 'Vega Distribution LLC', 'ky_filer_address' => '1 Main St',
            'ky_filer_city' => 'Louisville', 'ky_filer_state' => 'KY', 'ky_filer_zip' => '40202', 'ky_rep_first' => 'Test', 'ky_rep_last' => 'Owner',
            'ky_rep_title' => 'Owner', 'ky_rep_phone' => '(502) 555-0100', 'ky_rep_email' => 'test@example.com'] as $k => $v) {
            Setting::put($k, $v);
        }
    }

    private function bal(string $code): string
    {
        $id = Account::where('code', $code)->value('id');

        return bcsub((string) JournalLine::where('account_id', $id)->sum('debit'), (string) JournalLine::where('account_id', $id)->sum('credit'), 4);
    }

    private function receive(Product $p, string $qty, string $cost, ?Vendor $v = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(['vendor_id' => ($v ?? Vendor::factory()->create(['fein' => '987654321', 'address' => '9 Supply Rd', 'city' => 'Nashville', 'state' => 'TN', 'zip' => '37201']))->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_cost' => $cost]);

        return app(PurchaseOrderService::class)->receive($po);
    }

    private function invoicedSale(Product $p, string $qty, string $type = 'retailer'): SalesOrder
    {
        $c = Customer::factory()->create(['type' => $type, 'fein' => '111222333']);
        CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'type' => 'tobacco_retail', 'number' => 'R-555-'.$c->id]);
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'address' => '5 Shop St', 'city' => 'Lexington', 'zip' => '40507']);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);
        $svc = app(SalesOrderService::class);
        $svc->confirm($so);
        $svc->deliver($so);
        $svc->invoice($so->fresh());

        return $so->fresh();
    }

    private function onHand(Product $p): string
    {
        return (string) Inventory::where('product_id', $p->id)->sum('qty');
    }

    public function test_credit_memo_restock_and_damage_post_reversing_entries(): void
    {
        $p = Product::factory()->create(['tax_class' => 'pipe', 'price' => '10', 'cost' => '4']);
        $this->receive($p, '10', '4');
        $so = $this->invoicedSale($p, '5');
        $before = $this->onHand($p);
        $svc = app(CreditMemoService::class);
        $line = $so->lines->first();
        $cm = $svc->post($svc->create($so, [['sales_order_line_id' => $line->id, 'qty' => '2', 'disposition' => 'restock'],
        ], 'wrong item'));
        $this->assertSame('posted', $cm->status);
        $this->assertSame('20.0000', $cm->total);
        $this->assertEquals((float) $before + 2, (float) $this->onHand($p));
        $cm2 = $svc->post($svc->create($so->fresh(), [['sales_order_line_id' => $line->id, 'qty' => '1', 'disposition' => 'damage']]));
        $this->assertEquals((float) $before + 2, (float) $this->onHand($p)); // damage not restocked
        $this->assertSame('-20.0000', $this->bal('4000')); // 50 sales less 30 credits
        $this->assertSame('4.0000', $this->bal('6300'));
        $this->assertSame('20.0000', $so->fresh()->balance());
        $this->expectException(WorkflowException::class);
        $svc->post($svc->create($so->fresh(), [['sales_order_line_id' => $line->id, 'qty' => '3']])); // only 2 left
    }

    public function test_reports_include_returns_and_xml_validates(): void
    {
        $pipe = Product::factory()->create(['tax_class' => 'pipe', 'price' => '10', 'manufacturer' => 'Pipe Co', 'manufacturer_fein' => '555666777', 'brand' => 'Briar', 'msa_status' => 'N/A']);
        $snuff = Product::factory()->create(['tax_class' => 'snuff_chew', 'smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 5, 'price' => '24', 'unit' => 'can']);
        $this->receive($pipe, '20', '5');
        $this->receive($snuff, '20', '5');
        $so = $this->invoicedSale($pipe, '3');
        $this->invoicedSale($snuff, '2');
        $svc = app(CreditMemoService::class);
        $svc->post($svc->create($so, [['sales_order_line_id' => $so->lines->first()->id, 'qty' => '1']]));

        [$s, $e] = [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()];
        $r = TaxReport::for('ky_tobacco_distributor')->build($s, $e);
        $lines = collect($r['summary'])->keyBy(0);
        $this->assertSame('-10.00', $lines['2'][2]);
        $this->assertSame('20.00', $lines['3'][2]);
        $this->assertCount(1, $r['sections']['returns']['rows']);

        $gen = new KyTobaccoXml;
        $xml = $gen->generate($s, $e);
        $this->assertSame([], KyTobaccoXml::validate($xml), $xml);
        $d = new \DOMDocument;
        $d->loadXML($xml);
        $x = new \DOMXPath($d);
        $this->assertSame('KYTobacco2017V1.5', $x->evaluate('string(/TobaccoTransmission/ReturnState/@stateSchemaVersion)'));
        $this->assertSame('TOB', $x->evaluate('string(//ReturnHeaderState/ReturnType)'));
        $this->assertSame('123456789', $x->evaluate('string(//Filer/EIN)'));
        $scheds = array_map(fn ($n) => $n->nodeValue, iterator_to_array($x->query('//Schedule/TypeOfSchedule')));
        $this->assertSame(['1B', '1C', '2C'], $scheds);
        $this->assertSame('Credit Memo', $x->evaluate("string(//Schedule[TypeOfSchedule='1C']//TypeOfTransDoc)"));
        $this->assertSame('KY-MS1', $x->evaluate("string(//Schedule[TypeOfSchedule='2C']//Transactions[FedDesc='Snuff']/StateDesc)"));
        $this->assertSame('10', $x->evaluate("string(//Schedule[TypeOfSchedule='2C']//Transactions[FedDesc='Snuff']/ExtendedTaxableAmount)")); // Unit 1 * Qty 10
        $this->assertSame('CAN', $x->evaluate("string(//Transactions[FedDesc='Snuff']/UnitDescription)"));
        $this->assertSame('30.00', $x->evaluate("string(//Schedule[TypeOfSchedule='2C']//Transactions[StateDesc='KY-TP1']/ExtendedTaxableAmount)")); // Value * Qty
        // element order inside Transactions follows the guide / XSD
        $names = array_map(fn ($n) => $n->nodeName, iterator_to_array($x->query('(//Transactions)[1]/*')));
        $this->assertSame(['FedDesc', 'StateDesc', 'MSAStatus', 'Price', 'TaxJurisdiction', 'ProductDescription', 'Manufacturer', 'ManufacturerEIN', 'BrandFamily',
            'Unit', 'UnitDescription', 'WeightVolume', 'Value', 'Quantity', 'StickCount', 'ExtendedTaxableAmount'], $names);
        $this->assertNotEmpty($gen->warnings); // snuff has no manufacturer FEIN

        // amended + empty period
        $empty = (new KyTobaccoXml)->generate($s->subYear(), $e->subYear(), true);
        $this->assertSame([], KyTobaccoXml::validate($empty));
        $this->assertStringContainsString('<NoTransactions>X</NoTransactions>', $empty);
        $this->assertStringContainsString('<AmendedReturnIndicator>X</AmendedReturnIndicator>', $empty);
        // bad StateDesc override is caught by the schema
        $pipe->update(['ky_state_desc' => 'KY-BOGUS']);
        $this->assertNotEmpty(KyTobaccoXml::validate((new KyTobaccoXml)->generate($s, $e)));
    }

    public function test_cigarette_xml_validates_against_official_schema(): void
    {
        $cig = Product::factory()->create(['tax_class' => 'cigarette_stamped', 'pact_covered' => true, 'sticks_per_unit' => 200, 'price' => '62.50',
            'upc' => '012345678905', 'manufacturer' => 'Big Tobacco', 'manufacturer_fein' => '444555666', 'brand' => 'Marlboro', 'msa_status' => 'OPM']);
        $this->receive($cig, '10', '55');
        $so = $this->invoicedSale($cig, '3');
        $this->invoicedSale($cig, '2', 'subjobber');
        $svc = app(CreditMemoService::class);
        $svc->post($svc->create($so, [['sales_order_line_id' => $so->lines->first()->id, 'qty' => '1', 'disposition' => 'damage']]));

        [$s, $e] = [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()];
        $r = TaxReport::for('ky_subjobber_cigarette')->build($s, $e);
        $this->assertContains('1C', array_column($r['sections']['receipts']['rows'], 0));
        $gen = new KyCigaretteXml;
        $xml = $gen->generate($s, $e);
        $this->assertSame([], KyCigaretteXml::validate($xml), $xml);
        $this->assertStringContainsString('<CigaretteTransmission>', $xml);
        $this->assertStringContainsString('<ReturnType>CIG</ReturnType>', $xml);
        $this->assertStringContainsString('stateSchemaVersion="KYCigarette2017V1.4"', $xml);
        $d = new \DOMDocument;
        $d->loadXML($xml);
        $x = new \DOMXPath($d);
        $this->assertSame('2000', $x->evaluate("string(//Schedule[TypeOfSchedule='1B']//TotalSticks)"));
        $this->assertSame('CAR', $x->evaluate("string(//Schedule[TypeOfSchedule='2C']//UPCUOM)"));
        $this->assertSame('200', $x->evaluate("string(//Schedule[TypeOfSchedule='1C']//TotalSticks)"));
        $this->assertSame([], $gen->warnings);
    }

    public function test_download_xml_buttons_and_excise_payment(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(KyTobaccoDistributorReport::class)->set('period', now()->format('Y-m'))->callAction('xml')->assertFileDownloaded();
        Livewire::test(KySubjobberReport::class)->set('period', now()->format('Y-m'))->callAction('xml')->assertFileDownloaded();
        Livewire::test(KySubjobberReport::class)->callAction('csv')->assertFileDownloaded();

        $f = TaxFiling::create(['report_type' => 'ky_tobacco_distributor', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'tax_due' => '100', 'status' => 'filed', 'summary' => [], 'data' => []]);
        app(PostingService::class)->excisePayment($f, '100', '2026-10-20', 'MyTaxes 123');
        $this->assertSame('100.0000', $this->bal('2100'));
        $this->assertSame('-100.0000', $this->bal('1000'));
        $this->assertSame('MyTaxes 123', $f->fresh()->payment_reference);
    }
}
