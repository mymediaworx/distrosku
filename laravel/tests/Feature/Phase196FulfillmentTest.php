<?php

namespace Tests\Feature;

use App\Delivery\RoutePlanner;
use App\Delivery\VanSaleService;
use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Livewire\CounterPos;
use App\Livewire\Driver\VanSale;
use App\Livewire\Portal\Catalog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DeliveryManifest;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Warehouse;
use App\Portal\PortalService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.19.6: every sale is counter pickup unless Delivery is chosen; only delivery needs (and shows) a ship-to. */
class Phase196FulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    }

    private function abc(): Customer
    {
        return Customer::create(['code' => 'ABC196', 'name' => 'ABC Convenience Store', 'state' => 'KY']); // no ship-to on file
    }

    private function gen(): Product
    {
        return Product::where('sku', 'TST-GEN-001')->first();
    }

    public function test_new_sale_defaults_to_pickup_without_ship_to(): void
    {
        $so = SalesOrder::create(['customer_id' => $this->abc()->id, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $this->assertSame('pickup', $so->fulfillment);
        $this->assertNull($so->ship_to_id);
        $this->assertSame('Counter pickup', $so->fulfillmentLabel());
        // the ship-to's own setting no longer turns a sale into a delivery
        $ky = Customer::where('code', 'CTEST-KY')->first();
        $ky->defaultShipTo()->update(['fulfillment' => 'delivery']);
        $this->assertSame('pickup', SalesOrder::create(['customer_id' => $ky->id, 'ship_to_id' => $ky->defaultShipTo()->id,
            'warehouse_id' => Warehouse::value('id'), 'order_date' => now()->toDateString()])->fulfillment);
    }

    public function test_delivery_requires_ship_to(): void
    {
        $this->expectException(ValidationException::class);
        SalesOrder::create(['customer_id' => $this->abc()->id, 'fulfillment' => 'delivery', 'warehouse_id' => Warehouse::value('id'), 'order_date' => now()->toDateString()]);
    }

    public function test_admin_form_pickup_hides_ship_to_delivery_requires_it(): void
    {
        $c = $this->abc();
        Livewire::test(CreateSalesOrder::class)->fillForm(['customer_id' => $c->id])
            ->assertFormSet(['fulfillment' => 'pickup', 'ship_to_id' => null])->assertFormFieldIsHidden('ship_to_id')
            ->fillForm(['fulfillment' => 'delivery'])->assertFormFieldIsVisible('ship_to_id')
            ->call('create')->assertHasFormErrors(['ship_to_id' => 'required']);
        $ky = Customer::where('code', 'CTEST-KY')->first();
        Livewire::test(CreateSalesOrder::class)->fillForm(['customer_id' => $ky->id, 'fulfillment' => 'delivery'])
            ->assertFormSet(['ship_to_id' => $ky->defaultShipTo()->id]); // delivery prefills the default ship-to
    }

    public function test_pos_abc_customer_without_ship_to_completes_sale(): void
    {
        $c = $this->abc();
        $p = $this->gen();
        $lw = Livewire::test(CounterPos::class)->set('customerId', $c->id)
            ->assertSet('fulfillment', 'pickup')->assertSet('shipToId', null)
            ->assertDontSee('No ship-to')->assertSee('Counter pickup');
        $lw->set('scan', $p->sku)->call('add')->set('tender', 'cash')->call('checkout')->assertSet('error', null);
        $so = SalesOrder::where('channel', 'pos')->where('customer_id', $c->id)->latest('id')->first();
        $this->assertNotNull($so);
        $this->assertSame('pickup', $so->fulfillment);
        $this->assertNull($so->ship_to_id);
        $this->assertSame('invoiced', $so->status->value);
    }

    public function test_pos_delivery_prefills_default_and_requires_ship_to(): void
    {
        $ky = Customer::where('code', 'CTEST-KY')->first();
        Livewire::test(CounterPos::class)->set('customerId', $ky->id)->assertSet('shipToId', null)
            ->set('fulfillment', 'delivery')->assertSet('shipToId', $ky->defaultShipTo()->id)
            ->set('fulfillment', 'pickup')->assertSet('shipToId', null);
        $abc = $this->abc();
        Livewire::test(CounterPos::class)->set('customerId', $abc->id)->set('fulfillment', 'delivery')->assertSee('No ship-to for delivery')
            ->set('scan', $this->gen()->sku)->call('add')->call('checkout')->assertSet('error', 'Remove blocked items before checkout.');
    }

    public function test_van_pickup_needs_no_ship_to_delivery_does(): void
    {
        $abc = $this->abc();
        $van = Warehouse::create(['code' => 'VAN196', 'name' => 'Van 196', 'is_van' => true, 'state' => 'KY']);
        app(\App\Services\InventoryService::class)->move($this->gen(), $van, '10', 'adjustment', null, $this->gen()->cost, 'load van');
        $so = app(VanSaleService::class)->sell($van, $abc, null, [['product_id' => $this->gen()->id, 'qty' => 1]], 'cash');
        $this->assertSame('pickup', $so->fulfillment);
        $this->assertNull($so->ship_to_id);
        $this->expectException(\App\Exceptions\WorkflowException::class);
        app(VanSaleService::class)->sell($van, $abc, null, [['product_id' => $this->gen()->id, 'qty' => 1]], 'cash', null, 'delivery');
    }

    public function test_van_screen_hides_ship_to_for_pickup(): void
    {
        $abc = $this->abc();
        Livewire::actingAs(User::where('role', 'driver')->first())->test(VanSale::class)->set('customerId', $abc->id)
            ->assertSet('fulfillment', 'pickup')->assertSet('shipToId', null)->assertDontSee('No ship-to')
            ->set('fulfillment', 'delivery')->assertSee('No ship-to for delivery');
    }

    public function test_portal_pickup_order_without_ship_to(): void
    {
        $abc = $this->abc();
        $k = CustomerContact::create(['customer_id' => $abc->id, 'name' => 'Pat', 'email' => 'pat196@example.test', 'is_portal_contact' => true]);
        $k->setPasswordFromLink('Secret12345');
        Auth::guard('portal')->login($k->fresh());
        Livewire::test(Catalog::class)->assertSet('fulfillment', 'pickup')->assertDontSee('No ship-to address on file');
        $so = app(PortalService::class)->submit($k->fresh(), null, [$this->gen()->id => '1']);
        $this->assertSame('pickup', $so->fulfillment);
        $this->assertNull($so->ship_to_id);
    }

    public function test_only_delivery_sales_are_routed_and_printables_say_counter_pickup(): void
    {
        $ky = Customer::where('code', 'CTEST-KY')->first();
        $svc = app(SalesOrderService::class);
        $mk = function (array $a) use ($ky) {
            $so = SalesOrder::create($a + ['customer_id' => $ky->id, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
            $so->lines()->create(['product_id' => $this->gen()->id, 'qty' => 1]);

            return $so->fresh();
        };
        $p = $svc->confirm($mk([]));
        $d = $svc->confirm($mk(['fulfillment' => 'delivery', 'ship_to_id' => $ky->defaultShipTo()->id]));
        $m = DeliveryManifest::create(['delivery_date' => now()->toDateString(), 'warehouse_id' => $p->warehouse_id, 'status' => 'open']);
        app(RoutePlanner::class)->plan($m, null, true);
        $ids = $m->stops()->pluck('sales_order_id')->all();
        $this->assertNotContains($p->id, $ids);
        $this->assertContains($d->id, $ids);
        $html = view('docs.invoice', ['so' => $p->fresh(), 'company' => \App\Models\Company::current(), 'info' => config('erp.company')])->render();
        $this->assertStringContainsString('Counter pickup', $html);
    }

    public function test_migration_backfills_null_fulfillment_and_reruns(): void
    {
        $ky = Customer::where('code', 'CTEST-KY')->first();
        $so = SalesOrder::create(['customer_id' => $ky->id, 'ship_to_id' => $ky->defaultShipTo()->id, 'warehouse_id' => Warehouse::value('id'), 'order_date' => now()->toDateString()]);
        DB::table('sales_orders')->where('id', $so->id)->update(['fulfillment' => null]);
        $m = include database_path('migrations/2026_11_16_000001_phase196_fulfillment_on_sale.php');
        $m->up();
        $m->up();
        $this->assertSame('pickup', $so->fresh()->fulfillment);
        $this->assertSame($ky->defaultShipTo()->id, $so->fresh()->ship_to_id); // ship-to data kept
        $this->assertTrue(collect(\Illuminate\Support\Facades\Schema::getColumns('sales_orders'))->firstWhere('name', 'ship_to_id')['nullable']);
    }
}
