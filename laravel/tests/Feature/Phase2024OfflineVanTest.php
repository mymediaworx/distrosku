<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #26 (0.20.24): van sales with no signal: driver page + catalog + sync through VanSaleService. */
class Phase2024OfflineVanTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Warehouse $van;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->driver = User::factory()->create(['role' => 'driver']);
        $this->van = Warehouse::where('is_van', true)->first()
            ?? Warehouse::create(['code' => 'VAN1', 'name' => 'Van 1', 'is_van' => true, 'active' => true]);
        $this->van->update(['van_driver_id' => $this->driver->id, 'active' => true]);
        app(InventoryService::class)->move(Product::where('sku', 'TST-GEN-001')->first(), $this->van, 10, 'receipt');
    }

    private function sale(array $over = []): array
    {
        $gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();

        return array_replace(['uuid' => (string) Str::uuid(), 'sold_at' => now()->subMinutes(20)->toIso8601String(), 'catalog_at' => now()->subHour()->toIso8601String(),
            'user_id' => $this->driver->id, 'warehouse_id' => $this->van->id, 'customer_id' => Customer::where('code', 'CTEST-KY')->value('id'),
            'fulfillment' => 'pickup', 'tender' => 'cash', 'total' => '25.00', 'lines' => [['product_id' => $gen->id, 'qty' => '1', 'price' => (string) $gen->price]]], $over);
    }

    public function test_driver_gets_the_offline_page_for_their_van(): void
    {
        $html = $this->actingAs($this->driver)->get(route('driver.van.offline'))->assertOk()->getContent();
        $this->assertStringContainsString('Offline van sale · '.$this->van->name, $html);
        $this->assertStringContainsString('driver\\/van\\/offline\\/sync', $html);
        $this->assertStringContainsString("'distrosku-van'", $html);
        $this->assertStringContainsString("scope: '\\/driver'", $html);                    // service worker scope
        $this->actingAs($this->driver)->getJson(route('driver.van.offline.catalog'))->assertOk()->assertJsonStructure(['at', 'products', 'customers']);
    }

    public function test_offline_van_sale_syncs_through_the_van_service_and_moves_van_stock(): void
    {
        $gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $before = (float) \App\Models\Inventory::where('warehouse_id', $this->van->id)->where('product_id', $gen->id)->value('qty');
        $res = $this->actingAs($this->driver)->postJson(route('driver.van.offline.sync'), ['device_id' => 'phone-1', 'sales' => [$this->sale()]])->assertOk()->json('results.0');
        $this->assertSame('completed', $res['status'], json_encode($res));
        $row = OfflineSale::firstOrFail();
        $this->assertSame('van', $row->channel);
        $this->assertSame($this->van->id, (int) $row->salesOrder->warehouse_id);
        $this->assertStringContainsString('Offline van sale', $row->salesOrder->notes);
        $this->assertEqualsWithDelta($before - 1, (float) \App\Models\Inventory::where('warehouse_id', $this->van->id)->where('product_id', $gen->id)->value('qty'), 0.0001);
    }

    public function test_a_non_van_warehouse_goes_to_review_not_lost(): void
    {
        $main = Warehouse::where('code', 'MAIN')->value('id');
        $res = $this->actingAs($this->driver)->postJson(route('driver.van.offline.sync'), ['sales' => [$this->sale(['warehouse_id' => $main])]])->json('results.0');
        $this->assertSame('needs_review', $res['status']);
    }
}
