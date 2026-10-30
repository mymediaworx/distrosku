<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #26 device side (0.20.24): offline POS page, the catalog it keeps on the device, the service worker, the POS banner. */
class Phase2024OfflinePosDeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->cashier = User::factory()->create(['role' => 'sales']);
    }

    public function test_offline_page_and_catalog_need_a_pos_login(): void
    {
        $this->get(route('pos.offline'))->assertRedirect();
        $this->getJson(route('pos.offline.catalog'))->assertUnauthorized();
    }

    public function test_offline_page_has_what_it_needs_to_run_without_signal(): void
    {
        $html = $this->actingAs($this->cashier)->get(route('pos.offline'))->assertOk()->getContent();
        $this->assertStringContainsString('name="csrf-token"', $html);
        $this->assertStringContainsString('pos\\/offline\\/sync', $html);                         // same-origin paths
        $this->assertStringContainsString('pos\\/offline\\/catalog', $html);
        $this->assertStringContainsString("'distrosku-pos'", $html);
        $this->assertStringContainsString('/pos-sw.js', $html);
        $this->assertStringNotContainsString('data-tender="card"', $html);                 // card can't be taken offline
        foreach (['cash', 'check', 'account'] as $t) {
            $this->assertStringContainsString('data-tender="'.$t.'"', $html);
        }
        $this->assertStringNotContainsString('livewire', strtolower($html));                // no server round trips needed
    }

    public function test_catalog_has_products_codes_and_customers_but_no_costs(): void
    {
        $gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $ctn = $gen->units()->create(['code' => 'CTN', 'name' => 'Carton', 'factor' => 10]);
        $gen->barcodes()->create(['code' => '20000000000107', 'product_unit_id' => $ctn->id]);
        $archived = Product::factory()->create(['sku' => 'OLD-0001']);
        $archived->archive();

        $json = $this->actingAs($this->cashier)->getJson(route('pos.offline.catalog'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertSame($this->cashier->id, $json['cashier']['id']);
        $this->assertNotEmpty($json['at']);
        $p = collect($json['products'])->firstWhere('sku', 'TST-GEN-001');
        $this->assertSame(['20000000000107', '10.0000', 'CTN'], [$p['codes'][0]['code'], $p['codes'][0]['factor'], $p['codes'][0]['unit']]);
        $this->assertArrayNotHasKey('cost', $p);
        $this->assertNull(collect($json['products'])->firstWhere('id', $archived->id));
        $this->assertTrue(collect($json['products'])->firstWhere('sku', 'TST-CIG-001')['regulated']);

        $cash = collect($json['customers'])->firstWhere('code', 'CASH');
        $this->assertTrue($cash['walk_in']);
        $this->assertSame(['id', 'code', 'name', 'dba', 'walk_in'], array_keys($cash));         // no balances / limits / contacts
        $this->assertNull(collect($json['customers'])->firstWhere('id', Customer::where('active', false)->value('id') ?? -1));
    }

    public function test_service_worker_only_caches_the_offline_page_navigation(): void
    {
        $sw = file_get_contents(public_path('pos-sw.js'));
        $this->assertStringContainsString("'/pos/offline'", $sw);
        $this->assertStringContainsString("'/driver/van/offline'", $sw);
        $this->assertStringContainsString("req.mode !== 'navigate'", $sw);                   // never caches data, Livewire or sync calls
        $this->assertStringContainsString("req.method !== 'GET'", $sw);
    }

    public function test_pos_shows_the_offline_banner_hook_and_registers_the_worker(): void
    {
        $this->actingAs($this->cashier)->get(route('pos'))->assertOk()
            ->assertSee('data-test="offline-banner"', false)->assertSee('/pos-sw.js', false)->assertSee(route('pos.offline'), false);
    }

    public function test_a_queued_sale_in_the_device_format_syncs_through_the_existing_endpoint(): void
    {
        $gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $cust = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $sale = ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'sold_at' => now()->subMinutes(30)->toIso8601String(), 'catalog_at' => now()->subHour()->toIso8601String(),
            'user_id' => $this->cashier->id, 'warehouse_id' => \App\Models\Warehouse::where('code', 'MAIN')->value('id'), 'customer_id' => $cust->id,
            'fulfillment' => 'pickup', 'tender' => 'cash', 'total' => '50.00', 'lines' => [['product_id' => $gen->id, 'qty' => '2', 'price' => (string) $gen->price]]];

        $res = $this->actingAs($this->cashier)->postJson(route('pos.offline.sync'), ['device_id' => 'tablet-x', 'sales' => [$sale]])->assertOk()->json('results.0');
        $this->assertSame([$sale['uuid'], 'completed'], [$res['uuid'], $res['status']]);
        $this->assertNotEmpty($res['number']);
    }
}
