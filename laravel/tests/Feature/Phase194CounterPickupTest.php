<?php

namespace Tests\Feature;

use App\Delivery\RoutePlanner;
use App\Delivery\SignatureService;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\ShipTosRelationManager;
use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Livewire\CounterPos;
use App\Models\Customer;
use App\Models\DeliveryManifest;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class Phase194CounterPickupTest extends TestCase
{
    use RefreshDatabase;

    private Customer $ky;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
    }

    private function order(?ShipTo $st = null, array $a = []): SalesOrder
    {
        $st ??= $this->ky->defaultShipTo();
        $so = SalesOrder::create($a + ['customer_id' => $st->customer_id, 'ship_to_id' => $st->id, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'qty' => 1]);

        return $so->fresh();
    }

    public function test_existing_and_new_ship_tos_default_to_pickup(): void
    {
        $this->assertSame(0, ShipTo::where('fulfillment', '!=', 'pickup')->count());
        $st = ShipTo::create(['customer_id' => $this->ky->id, 'name' => 'New', 'state' => 'KY']);
        $this->assertSame('pickup', $st->fresh()->fulfillment);
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        Livewire::test(ShipTosRelationManager::class, ['ownerRecord' => $this->ky, 'pageClass' => EditCustomer::class])
            ->mountTableAction('create')->assertTableActionDataSet(['fulfillment' => 'pickup'])
            ->setTableActionData(['name' => 'Truck stop', 'state' => 'KY', 'fulfillment' => 'delivery'])->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('delivery', ShipTo::where('name', 'Truck stop')->value('fulfillment'));
    }

    public function test_orders_are_pickup_unless_delivery_chosen(): void
    {
        $this->assertSame('pickup', $this->order()->fulfillment);
        $d = ShipTo::create(['customer_id' => $this->ky->id, 'name' => 'Route stop', 'state' => 'KY', 'fulfillment' => 'delivery']);
        $this->assertSame('pickup', $this->order($d)->fulfillment); // 0.19.6: the ship-to no longer decides
        $this->assertSame('delivery', $this->order(null, ['fulfillment' => 'delivery'])->fulfillment); // user choice kept
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        Livewire::test(CreateSalesOrder::class)->fillForm(['customer_id' => $this->ky->id])->assertFormSet(['fulfillment' => 'pickup', 'ship_to_id' => null]);
        // portal + POS
        $this->assertSame('pickup', SalesOrder::create(['customer_id' => $this->ky->id, 'ship_to_id' => $this->ky->defaultShipTo()->id, 'channel' => 'portal',
            'warehouse_id' => Warehouse::value('id'), 'order_date' => now()->toDateString()])->fulfillment);
        Livewire::test(CounterPos::class)->set('cart', [])->set('scan', 'TST-GEN-001')->call('add')->call('checkout');
        $this->assertSame('pickup', SalesOrder::where('channel', 'pos')->value('fulfillment'));
    }

    public function test_pickup_never_auto_scheduled_but_delivery_and_legacy_are(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        $svc = app(SalesOrderService::class);
        $p = $svc->confirm($this->order());
        $d = $svc->confirm($this->order(ShipTo::create(['customer_id' => $this->ky->id, 'name' => 'R', 'state' => 'KY', 'fulfillment' => 'delivery']), ['fulfillment' => 'delivery']));
        $legacy = $svc->confirm($this->order());
        DB::table('sales_orders')->where('id', $legacy->id)->update(['fulfillment' => null]); // pre-0.19.4 order: 0.19.6 migration backfills open ones to pickup
        $m = DeliveryManifest::create(['delivery_date' => now()->toDateString(), 'warehouse_id' => $p->warehouse_id, 'status' => 'open']);
        app(RoutePlanner::class)->plan($m, null, true);
        $ids = $m->stops()->pluck('sales_order_id')->all();
        $this->assertNotContains($p->id, $ids);
        $this->assertContains($d->id, $ids);
        $this->assertNotContains($legacy->id, $ids);
    }

    public function test_regulated_pickup_still_needs_signature(): void
    {
        $st = $this->ky->defaultShipTo();
        $so = SalesOrder::create(['customer_id' => $this->ky->id, 'ship_to_id' => $st->id, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => Product::where('sku', 'TST-CIG-001')->value('id'), 'qty' => 1]);
        $this->assertSame('pickup', $so->fresh()->fulfillment);
        config(['erp.require_signature' => true]);
        $this->assertTrue(app(SignatureService::class)->required($so->fresh()));
    }

    public function test_migration_rerunnable_and_logged(): void
    {
        $m = include database_path('migrations/2026_11_15_000001_phase194_counter_pickup_default.php');
        DB::table('ship_tos')->update(['fulfillment' => 'delivery']);
        $m->up(); // column exists: no second flip
        $this->assertSame(0, ShipTo::where('fulfillment', 'pickup')->count());
        $this->assertTrue(Schema::hasColumn('sales_orders', 'fulfillment'));
        $this->assertNotNull(\App\Models\Setting::get('phase194_shiptos_flipped'));
        $this->assertTrue(DB::table('activity_logs')->where('event', 'shipto_fulfillment_migrated')->exists());
    }
}
