<?php

namespace Tests\Feature;

use App\Filament\Pages\MsaFixList;
use App\Filament\Pages\MsaWeekly;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShipToResource;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\MsaSubmission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Msa\MsaSettings;
use App\Msa\MulticatBuilder;
use App\Services\CreditMemoService;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class Phase7MsaTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    private Product $cig;

    private Product $snuff;

    private Product $noCode;

    private ShipTo $st;

    private SalesOrder $so;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        Storage::fake('local');
        $this->wh = Warehouse::where('code', 'MAIN')->first();
        MsaSettings::put(['msa_distributor_id' => '12345678', 'msa_name' => 'VEGA DISTRIBUTION (TEST)', 'msa_address' => '100 Test Rd',
            'msa_city' => 'Louisville', 'msa_state' => 'KY', 'msa_zip' => '40202-1234', 'msa_contact_first' => 'Test', 'msa_contact_last' => 'Person',
            'msa_phone' => '(502) 555-0100']);

        $this->travelTo('2026-10-12 09:00:00'); // Monday of the week ending Sat 2026-10-17
        $this->cig = Product::factory()->create(['sku' => 'MARL-GLD-KS', 'upc' => '028200003843', 'name' => 'Marlboro Gold Pk King Box',
            'tax_class' => 'cigarette_stamped', 'msa_category_code' => '003231', 'msa_items_per_unit' => 200, 'price' => '80']);
        $this->snuff = Product::factory()->create(['sku' => 'COP-LC-5', 'upc' => '073100014610', 'name' => 'Copenhagen LC Natural 5 Can Roll',
            'tax_class' => 'snuff_chew', 'net_weight_oz' => '1.2', 'msa_category_code' => '003211', 'msa_items_per_unit' => 5, 'price' => '30']);
        $this->noCode = Product::factory()->create(['sku' => 'GRZ-WG', 'tax_class' => 'snuff_chew', 'net_weight_oz' => '1.2', 'price' => '5']);
        foreach ([$this->cig, $this->snuff, $this->noCode] as $p) {
            $po = PurchaseOrder::create(['vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
            $po->lines()->create(['product_id' => $p->id, 'qty' => '50', 'unit_cost' => '10']);
            app(PurchaseOrderService::class)->receive($po);
        }
        $c = Customer::factory()->create(['code' => 'C1001', 'name' => 'Corner Mart LLC', 'type' => 'retailer', 'phone' => '502-555-0199']);
        CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'type' => 'tobacco_retail']);
        $this->st = ShipTo::factory()->create(['customer_id' => $c->id, 'name' => 'Corner Mart', 'address' => '12 Elm St', 'city' => 'Lexington',
            'state' => 'KY', 'zip' => '40505', 'msa_shipping_number' => 'S01']);

        $this->travelTo('2026-10-14 10:00:00');
        $this->so = $this->sale([[$this->cig, '10'], [$this->snuff, '4'], [$this->noCode, '3']]);
        $this->travelTo('2026-10-16 11:00:00');
        $cms = app(CreditMemoService::class);
        $line = fn ($p) => $this->so->lines()->where('product_id', $p->id)->value('id');
        $cms->post($cms->create($this->so->fresh(), [['sales_order_line_id' => $line($this->cig), 'qty' => '2', 'disposition' => 'restock']]));
        $cms->post($cms->create($this->so->fresh(), [['sales_order_line_id' => $line($this->snuff), 'qty' => '1', 'disposition' => 'damage']]));
        app(InventoryService::class)->move($this->snuff, $this->wh, '-3', 'vendor_return', null, null, 'Floor return to mfr');

        $this->travelTo('2026-10-19 08:00:00'); // Monday after: a later sale must not change last week's closing inventory
        $this->sale([[$this->cig, '5']]);
    }

    private function sale(array $lines): SalesOrder
    {
        $so = SalesOrder::create(['customer_id' => $this->st->customer_id, 'ship_to_id' => $this->st->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        foreach ($lines as [$p, $q]) {
            $so->lines()->create(['product_id' => $p->id, 'qty' => $q, 'unit_price' => $p->price]);
        }
        $svc = app(SalesOrderService::class);
        $so = $svc->confirm($so->fresh());
        $svc->pick($so);
        $svc->deliver($so);

        return $svc->invoice($so);
    }

    private static function col(string $l, int $a, int $b): string
    {
        return substr($l, $a - 1, $b - $a + 1);
    }

    private function lines(array $r, string $type): array
    {
        return array_values(array_filter($r['lines'], fn ($l) => str_starts_with($l, $type)));
    }

    public function test_defaults_saturday_week_and_test_flag(): void
    {
        $this->assertSame('2026-10-17', MulticatBuilder::defaultWeekEnding('2026-10-19'));
        $this->assertSame('2026-10-10', MulticatBuilder::defaultWeekEnding('2026-10-17')); // week not over yet on its last day
        $this->assertTrue(MsaSettings::bool('msa_test_mode'));
        MsaSettings::put(['msa_week_end_day' => '5']);
        $this->assertSame('2026-10-16', MulticatBuilder::defaultWeekEnding('2026-10-19'));
    }

    public function test_standard_file_records_and_totals(): void
    {
        $r = app(MulticatBuilder::class)->build('2026-10-17', ['created' => '20261019', 'profile' => 'spec']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('20261017.TOB', $r['file_name']);
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'GRZ-WG')));

        $hid = $r['lines'][0];
        $this->assertSame(337, strlen($hid));
        $this->assertSame('HID12345678TOB TW20261017', substr($hid, 0, 25));
        $this->assertSame('402021234', self::col($hid, 175, 183));
        $this->assertSame('5025550100', self::col($hid, 232, 241));
        $this->assertSame('000100000001', self::col($hid, 317, 328));
        $this->assertSame('20261019', self::col($hid, 329, 336));

        $bids = $this->lines($r, 'BID');
        $this->assertCount(2, $bids); // GRZ-WG has no MSA code
        $cig = collect($bids)->first(fn ($l) => str_contains($l, 'MARL-GLD-KS'));
        $this->assertSame(261, strlen($cig));
        $this->assertSame('  028200003843', self::col($cig, 4, 17));
        $this->assertSame('000MARL-GLD-KS', self::col($cig, 18, 31));
        $this->assertSame('000200', self::col($cig, 132, 137));
        $this->assertSame('003231', self::col($cig, 145, 150));
        $this->assertSame('003', self::col($cig, 248, 250));
        $this->assertSame('00000000042', self::col($cig, 251, 261)); // 50 - 10 + 2 restocked (Monday sale of 5 excluded)
        $snuff = collect($bids)->first(fn ($l) => str_contains($l, 'COP-LC-5'));
        $this->assertSame('00000000043', self::col($snuff, 251, 261)); // 50 - 4 - 3 vendor return; damaged return not restocked

        $sids = $this->lines($r, 'SID');
        $this->assertCount(1, $sids);
        $this->assertSame(551, strlen($sids[0]));
        $this->assertSame('C1001   S01     ', self::col($sids[0], 4, 19));
        $this->assertSame(str_pad('Corner Mart', 32), self::col($sids[0], 28, 59));
        $this->assertSame('40505    ', self::col($sids[0], 185, 193));
        $this->assertSame('  ', self::col($sids[0], 197, 198)); // tax jurisdiction not reported by default
        $this->assertSame('5025550199', self::col($sids[0], 202, 211));
        $this->assertSame(str_pad('Retailer', 20), self::col($sids[0], 212, 231));
        $this->assertSame('N', self::col($sids[0], 239, 239));
        $this->assertSame('N', self::col($sids[0], 498, 498));

        $purs = $this->lines($r, 'PUR');
        $this->assertCount(4, $purs); // cig ship, cig return, snuff ship, snuff return (never netted)
        $q = collect($purs)->map(fn ($l) => [trim(self::col($l, 28, 41), '0'), self::col($l, 106, 116)])->sortBy(fn ($x) => $x[0].$x[1])->values()->all();
        $this->assertSame([['COP-LC-5', '-0000000001'], ['COP-LC-5', '00000000004'], ['MARL-GLD-KS', '-0000000002'], ['MARL-GLD-KS', '00000000010']], $q);
        foreach ($purs as $l) {
            $this->assertSame(116, strlen($l));
            $this->assertSame('001', self::col($l, 103, 105));
            $this->assertSame('        ', self::col($l, 75, 82)); // no transaction date outside Altria mode
        }

        $tot = end($r['lines']);
        $this->assertSame(140, strlen($tot));
        $this->assertSame('TOT1234567820261017000000002000000001000000004', substr($tot, 0, 46));
        $this->assertSame('001000000000000011', self::col($tot, 87, 104)); // 10 - 2 + 4 - 1
        $this->assertSame('003000000000000085', self::col($tot, 123, 140)); // 42 + 43
        $this->assertStringEndsWith("\r\n", $r['content']);
    }

    public function test_altria_fields_and_dollars_when_enabled(): void
    {
        MsaSettings::put(['msa_altria_fields' => '1', 'msa_report_dollars' => '1', 'msa_tax_jurisdiction' => 'sid', 'msa_test_mode' => '0']);
        $r = app(MulticatBuilder::class)->build('2026-10-17', ['profile' => 'spec']);
        $this->assertSame([], $r['errors']);
        $hid = $r['lines'][0];
        $this->assertSame(' ', self::col($hid, 16, 16)); // live
        $this->assertSame('000200000004', self::col($hid, 317, 328));
        $snuff = collect($this->lines($r, 'BID'))->first(fn ($l) => str_contains($l, 'COP-LC-5'));
        $this->assertSame(275, strlen($snuff));
        $this->assertSame('006-0000000003', self::col($snuff, 262, 275)); // floor return to manufacturer
        $this->assertSame('KY', self::col($this->lines($r, 'SID')[0], 197, 198));
        $purs = collect($this->lines($r, 'PUR'));
        $ret = $purs->first(fn ($l) => str_contains($l, 'COP-LC-5') && self::col($l, 106, 106) === '-');
        $this->assertSame(158, strlen($ret));
        $this->assertSame('20261016', self::col($ret, 75, 82));
        $this->assertSame('002', self::col($ret, 117, 119));
        $this->assertSame('     -30.00', self::col($ret, 120, 130));
        $this->assertSame('00400000000000', self::col($ret, 131, 144));
        $this->assertSame('005-0000000001', self::col($ret, 145, 158)); // damaged = unsaleable
        $cigRet = $purs->first(fn ($l) => str_contains($l, 'MARL-GLD-KS') && self::col($l, 106, 106) === '-');
        $this->assertSame('004-0000000002', self::col($cigRet, 131, 144)); // restock = saleable
        $ship = $purs->first(fn ($l) => str_contains($l, 'MARL-GLD-KS') && self::col($l, 106, 106) !== '-');
        $this->assertSame('20261014', self::col($ship, 75, 82));
        $this->assertSame('     800.00', self::col($ship, 120, 130));
        $tot = end($r['lines']);
        $this->assertSame(194, strlen($tot));
        $this->assertSame('002', self::col($tot, 105, 107));
        $this->assertSame('006-00000000000003', self::col($tot, 141, 158));
        $this->assertSame('004-00000000000002', self::col($tot, 159, 176));
        $this->assertSame('005-00000000000001', self::col($tot, 177, 194));
    }

    public function test_selling_unit_conversion_and_partial_units(): void
    {
        $this->cig->update(['msa_units_per_sale' => '0.1']); // ERP sells packs, MSA reports cartons
        $r = app(MulticatBuilder::class)->build('2026-10-17', ['profile' => 'spec']);
        $ship = collect($this->lines($r, 'PUR'))->first(fn ($l) => str_contains($l, 'MARL-GLD-KS') && self::col($l, 106, 106) !== '-');
        $this->assertSame('00000000001', self::col($ship, 106, 116));
        $ret = collect($this->lines($r, 'PUR'))->first(fn ($l) => str_contains($l, 'MARL-GLD-KS') && self::col($l, 106, 106) === '-');
        $this->assertSame('-00000000.2', self::col($ret, 106, 116));
    }

    public function test_prechecks_block_generation(): void
    {
        MsaSettings::put(['msa_distributor_id' => '123']);
        $this->snuff->update(['msa_items_per_unit' => null]);
        $this->st->update(['zip' => '4050']);
        $b = app(MulticatBuilder::class);
        $r = $b->build('2026-10-16'); // Friday: wrong week-end day
        $all = implode("\n", $r['errors']);
        $this->assertStringContainsString('distributor ID', $all);
        $this->assertStringContainsString('items per selling unit', $all);
        $this->assertStringContainsString('ZIP', $all);
        $this->assertStringContainsString('week ends on Saturday', $all);
        $this->expectException(\RuntimeException::class);
        $b->generate('2026-10-17');
    }

    public function test_duplicate_shipping_numbers_are_errors(): void
    {
        $st2 = ShipTo::factory()->create(['customer_id' => $this->st->customer_id, 'name' => 'Corner Mart 2', 'msa_shipping_number' => 'S01']);
        $this->travelTo('2026-10-15 10:00:00');
        $so = SalesOrder::create(['customer_id' => $st2->customer_id, 'ship_to_id' => $st2->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $this->cig->id, 'qty' => '1', 'unit_price' => '80']);
        $svc = app(SalesOrderService::class);
        $svc->deliver($svc->pick($svc->confirm($so->fresh())));
        $this->travelTo('2026-10-19 09:00:00');
        $this->assertStringContainsString('not unique', implode("\n", app(MulticatBuilder::class)->build('2026-10-17')['errors']));
    }

    public function test_generate_archives_file(): void
    {
        $s = app(MulticatBuilder::class)->generate('2026-10-17');
        $this->assertInstanceOf(MsaSubmission::class, $s);
        $this->assertSame('20261017.TOB', $s->file_name);
        $this->assertTrue($s->test);
        $this->assertSame(4, $s->pur_count);
        Storage::disk('local')->assertExists($s->path);
        $this->assertSame($s->sha256, hash('sha256', Storage::disk('local')->get($s->path)));
    }

    public function test_suggestions(): void
    {
        $this->assertSame('003211', MulticatBuilder::suggestCategory($this->noCode));
        $this->assertSame('003231', MulticatBuilder::suggestCategory(new Product(['tax_class' => 'cigarette_stamped'])));
        $this->assertSame(200, MulticatBuilder::suggestItems(new Product(['tax_class' => 'cigarette_stamped'])));
        $this->assertSame('Distributor', MulticatBuilder::classOfTrade(new Customer(['type' => 'wholesaler'])));
    }

    public function test_pages_are_admin_level(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sales = User::factory()->create(['role' => 'sales']);
        $wh = User::factory()->create(['role' => 'warehouse']);
        $this->actingAs($admin)->get(MsaWeekly::getUrl())->assertOk()->assertSee('MSA weekly');
        $this->actingAs($admin)->get(MsaFixList::getUrl())->assertOk()->assertSee('GRZ-WG');
        foreach ([$sales, $wh] as $u) {
            $this->actingAs($u)->get(MsaWeekly::getUrl())->assertForbidden();
            $this->actingAs($u)->get(MsaFixList::getUrl())->assertForbidden();
        }
    }

    public function test_resource_forms_render_msa_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(ProductResource::getUrl('edit', ['record' => $this->cig]))->assertOk()->assertSee('Items per selling unit');
        $this->get(ShipToResource::getUrl('edit', ['record' => $this->st]))->assertOk()->assertSee('TDLinx');
        $this->get(CustomerResource::getUrl('edit', ['record' => $this->st->customer_id]))->assertOk()->assertSee('Class of trade');
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->get(ShipToResource::getUrl('edit', ['record' => $this->st]))->assertOk()->assertDontSee('TDLinx');
    }

    public function test_page_saves_settings_and_bulk_fix(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        Livewire::test(MsaWeekly::class)->set('data.msa_week_end_day', '5')->call('saveSettings')->assertHasNoErrors();
        $this->assertSame(5, MsaSettings::weekEndDay());
        Livewire::test(MsaFixList::class)->callTableBulkAction('suggest', [$this->noCode]);
        $this->assertSame('003211', $this->noCode->fresh()->msa_category_code);
    }
}
