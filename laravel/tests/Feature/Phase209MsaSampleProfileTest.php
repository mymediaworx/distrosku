<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Filament\Pages\MsaWeekly;
use App\Models\User;
use App\Msa\MsaSettings;
use App\Msa\MulticatBuilder;
use App\Msa\Writer;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 0.20.9: "match sample" MULTICAT profile. Golden structure derived from the distributor's accepted weekly file
 * (anonymized: every name, number and amount here is made up; the real sample is never committed).
 */
class Phase209MsaSampleProfileTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        Storage::fake('local');
        $this->wh = Warehouse::where('code', 'MAIN')->first();
        MsaSettings::put(['msa_distributor_id' => '12345678', 'msa_name' => 'Test Distribution', 'msa_address' => '1 Test Way',
            'msa_city' => 'Testville', 'msa_state' => 'KY', 'msa_zip' => '40000', 'msa_contact_first' => 'Pat', 'msa_contact_last' => 'Tester',
            'msa_phone' => '5025550100', 'msa_report_dollars' => '1']);
        $this->travelTo('2026-10-12 09:00:00');
    }

    private function product(string $sku, string $upc, string $price, string $cat = '003251', int $items = 50): Product
    {
        $p = Product::factory()->create(['sku' => $sku, 'upc' => $upc, 'name' => "Test cigar $sku", 'tax_class' => 'cigar',
            'msa_category_code' => $cat, 'msa_items_per_unit' => $items, 'price' => $price]);
        $po = PurchaseOrder::create(['vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => '100', 'unit_cost' => '1']);
        app(PurchaseOrderService::class)->receive($po);

        return $p;
    }

    private function shipTo(string $code, array $attrs = []): ShipTo
    {
        $c = Customer::factory()->create(['code' => $code, 'name' => "Store $code LLC", 'type' => 'retailer']);
        CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'type' => 'tobacco_retail']);

        return ShipTo::factory()->create(array_merge(['customer_id' => $c->id, 'name' => "Store $code", 'address' => "$code Main St",
            'city' => 'Testville', 'state' => 'KY', 'zip' => '40001', 'msa_shipping_number' => $code], $attrs));
    }

    private function sale(ShipTo $st, array $lines): void
    {
        $so = SalesOrder::create(['customer_id' => $st->customer_id, 'ship_to_id' => $st->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        foreach ($lines as [$p, $q, $price]) {
            $so->lines()->create(['product_id' => $p->id, 'qty' => $q, 'unit_price' => $price]);
        }
        $svc = app(SalesOrderService::class);
        $so = $svc->confirm($so->fresh());
        $svc->pick($so);
        $svc->deliver($so);
        $svc->invoice($so);
    }

    private function build(): array
    {
        $this->travelTo('2026-10-19 08:00:00');

        return app(MulticatBuilder::class)->build('2026-10-17', ['created' => '20261018']);
    }

    public function test_golden_structure_matches_accepted_sample(): void
    {
        $a = $this->product('4', '012345678905', '7.00');
        $b = $this->product('12', '614141000036', '1.005');
        $s13 = $this->shipTo('13');
        $s7 = $this->shipTo('7');
        $this->travelTo('2026-10-14 10:00:00');
        $this->sale($s13, [[$b, '1', '1.005'], [$a, '2', '7.00']]);
        $this->sale($s7, [[$b, '1', '1.005']]);
        $r = $this->build();

        $this->assertSame([], $r['errors']);
        $types = array_map(fn ($l) => substr($l, 0, 3).(in_array(substr($l, 0, 3), ['SID', 'PUR']) ? substr($l, 3, 8) : ''), $r['lines']);
        $this->assertSame(['HID', 'BID', 'BID', 'SID00000007', 'PUR00000007', 'SID00000013', 'PUR00000013', 'PUR00000013', 'TOT'], $types);
        foreach ($r['lines'] as $l) {
            $this->assertSame(['HID' => 337, 'BID' => 261, 'SID' => 551, 'PUR' => 130, 'TOT' => 140][substr($l, 0, 3)], strlen($l));
        }
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $r['content']));
        $this->assertStringEndsWith("\r\n", $r['content']);

        $hid = $r['lines'][0];
        $this->assertSame('HID12345678TOB TW20261017', substr($hid, 0, 25));
        $this->assertSame('000100000002', substr($hid, 316, 12));

        $bid = collect($r['lines'])->first(fn ($l) => str_starts_with($l, 'BID') && substr($l, 17, 14) === '00000000000004');
        $this->assertSame('  012345678905', substr($bid, 3, 14));
        $this->assertSame('000050N', substr($bid, 131, 7));
        $this->assertSame('00300000098.00', substr($bid, 247, 14)); // inventory: D2, zero-filled

        $sid = $r['lines'][3];
        $this->assertSame('0000000700000007', substr($sid, 3, 16));
        $this->assertSame(str_pad('Retail', 20), substr($sid, 211, 20));
        $this->assertSame('   ', substr($sid, 193, 3));           // country blank, as in the sample
        $this->assertSame(str_repeat(' ', 24), substr($sid, 258, 24)); // bill-to blank

        $pur = $r['lines'][4];
        $this->assertSame('00000000000012', substr($pur, 27, 14));
        $this->assertSame(str_repeat(' ', 38), substr($pur, 44, 38)); // invoice + date blank
        $this->assertSame('00100000001.0000200000001.01', substr($pur, 102, 28)); // 1.005 rounds to 1.01

        // TOT sums the rounded line values: 1.01 + 14.00 + 1.01 = 16.02 (unrounded sum would round to 16.01).
        $this->assertSame('TOT1234567820261017000000002000000002000000003', substr($r['lines'][8], 0, 46));
        $this->assertSame('001000000000004.00002000000000016.02003000000000196.00', substr($r['lines'][8], 86, 54));
        $this->assertSame('16.02', $r['totals']['dollars']);
    }

    public function test_prechecks_upc_state_po_box(): void
    {
        $zero = $this->product('Z1', '0', '1.00');
        $bad = $this->product('B1', '012345678901', '1.00');
        $odd = $this->product('O1', '123456', '1.00');
        $lower = $this->shipTo('21', ['state' => 'ky']);
        $box = $this->shipTo('22', ['address' => 'PO Box 12']);
        $this->travelTo('2026-10-14 10:00:00');
        $this->sale($lower, [[$zero, '1', '1.00'], [$bad, '1', '1.00']]);
        $this->sale($box, [[$odd, '1', '1.00']]);
        $r = $this->build();

        $this->assertTrue(collect($r['errors'])->contains(fn ($e) => str_contains($e, 'Z1') && str_contains($e, 'UPC is 0')));
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'B1') && str_contains($w, 'check digit')));
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'O1') && str_contains($w, 'unusual length')));
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, "'ky'") && str_contains($w, 'lowercase')));
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'PO box')));
        $this->assertSame('KY', substr(collect($r['lines'])->first(fn ($l) => str_starts_with($l, 'SID00000021')), 182, 2));
        $this->expectException(\RuntimeException::class);
        app(MulticatBuilder::class)->generate('2026-10-17');
    }

    public function test_gtin_check_digit_and_d2_format(): void
    {
        $this->assertTrue(Writer::gtinValid('012345678905'));
        $this->assertTrue(Writer::gtinValid('12345678905')); // 11 digits, leading 0 dropped
        $this->assertTrue(Writer::gtinValid('4006381333931'));
        $this->assertFalse(Writer::gtinValid('012345678901'));
        $this->assertSame('-0000060.00', Writer::field('-60', 11, 'D2'));
        $this->assertSame('00000084.00', Writer::field('84', 11, 'D2'));
    }

    public function test_spec_profile_still_available(): void
    {
        MsaSettings::put(['msa_profile' => 'spec']);
        $a = $this->product('4', '012345678905', '7.00');
        $s7 = $this->shipTo('7');
        $this->travelTo('2026-10-14 10:00:00');
        $this->sale($s7, [[$a, '2', '7.00']]);
        $r = $this->build();
        $sid = collect($r['lines'])->first(fn ($l) => str_starts_with($l, 'SID'));
        $this->assertSame('7       7       ', substr($sid, 3, 16));
        $this->assertSame('     14.00', substr(collect($r['lines'])->first(fn ($l) => str_starts_with($l, 'PUR')), 120, 10));
    }

    public function test_msa_weekly_tables_scroll_on_phones_issue_9_and_driver_sticky(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(MsaWeekly::getUrl())->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'data-test="msa-table-wrap"'));
        $this->assertStringContainsString('data-test="msa-files-wrap"', $html);
        $this->assertStringContainsString('File profile', $html);
        $driver = file_get_contents(resource_path('views/layouts/driver.blade.php'));
        $this->assertStringNotContainsString('body{overflow-x:hidden}', $driver);
        $this->assertStringContainsString('html{overflow-x:hidden}', $driver);
    }
}
