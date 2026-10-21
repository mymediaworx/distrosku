<?php

namespace Tests\Feature;

use App\Delivery\DeliveryDays;
use App\Delivery\DeliveryService;
use App\Delivery\RoutePlanner;
use App\Delivery\SignatureService;
use App\Delivery\VanSaleService;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\Modules;
use App\Http\Responses\LoginResponse;
use App\Livewire\CounterPos;
use App\Livewire\Driver\PickupSign;
use App\Livewire\Driver\StopScreen;
use App\Livewire\Driver\VanSale;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\DeliveryManifest;
use App\Models\DeliverySignature;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class Phase15DeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $requireSignature = true;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Customer $ky;

    private ShipTo $kyShip;

    private User $driver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
        $this->kyShip = $this->ky->shipTos()->first();
        $this->driver = User::where('role', 'driver')->first();
        $this->admin = User::factory()->create(['role' => 'owner', 'active' => true]);
    }

    private function order(string $sku, string $qty = '2', ?Customer $c = null): SalesOrder
    {
        $c ??= $this->ky;
        $p = Product::where('sku', $sku)->first();
        $so = SalesOrder::create(['number' => 'SO-T'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $c->shipTos()->value('id'),
            'warehouse_id' => Warehouse::where('code', $p->sku === 'TST-BEV-001' ? 'BEV' : 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);

        return app(SalesOrderService::class)->confirm($so);
    }

    private function sig(int $acks = 1, string $name = 'Pat Buyer'): array
    {
        return ['image' => self::PNG, 'name' => $name, 'accepted' => array_fill(0, $acks, true)];
    }

    private function manifest(array $orders): DeliveryManifest
    {
        $m = DeliveryManifest::create(['delivery_date' => now()->toDateString(), 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'driver_id' => $this->driver->id, 'status' => 'out']);
        foreach ($orders as $i => $so) {
            $m->stops()->create(['sales_order_id' => $so->id, 'sequence' => $i + 1]);
        }

        return $m;
    }

    public function test_regulated_flag_and_acknowledgment_texts(): void
    {
        $svc = app(SignatureService::class);
        foreach (['TST-CIG-001', 'TST-CGR-001', 'TST-VAP-001', 'TST-HMP-001', 'TST-KRT-001', 'TST-SNF-001'] as $sku) {
            $this->assertTrue($svc->requiresSignature([Product::where('sku', $sku)->first()]), $sku);
        }
        $this->assertFalse($svc->requiresSignature(Product::whereIn('sku', ['TST-GEN-001', 'TST-BEV-001'])->get()));
        $this->assertSame(['I warrant that I am 21 years of age or older.'], $svc->acknowledgments('X', '10', false));
        $this->assertSame('I acknowledge this charge to the account of TEST Corner Market (KY) for $1,234.50 and I warrant that I am authorized to make this purchase on its behalf.',
            $svc->acknowledgments('TEST Corner Market (KY)', '1234.5', true)[1]);
        $so = $this->order('TST-PIP-001');
        $this->assertTrue($svc->isAccountCharge($so)); // Net 15
        $cash = $this->order('TST-PIP-001', '1', Customer::where('code', 'CASH')->first());
        $this->assertFalse($svc->isAccountCharge($cash)); // COD
    }

    public function test_deliver_blocked_without_signature_and_non_regulated_needs_none(): void
    {
        $so = $this->order('TST-PIP-001');
        try {
            app(SalesOrderService::class)->deliver($so);
            $this->fail('expected block');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('signature', $e->getMessage());
        }
        $this->assertSame(S::Confirmed, $so->fresh()->status);
        $gen = $this->order('TST-GEN-001');
        app(SalesOrderService::class)->deliver($gen);
        $this->assertSame(S::Delivered, $gen->fresh()->status);
    }

    public function test_capture_validates_name_acks_and_image_and_snapshots(): void
    {
        $so = $this->order('TST-PIP-001');
        $svc = app(SignatureService::class);
        foreach ([['name' => ''], ['accepted' => [true]], ['image' => 'data:image/png;base64,AAAA']] as $bad) {
            try {
                $svc->capture($so, array_merge($this->sig(2), $bad), 'pickup');
                $this->fail('expected validation');
            } catch (ValidationException) {
            }
        }
        $this->actingAs($this->admin);
        $sig = $svc->capture($so, $this->sig(2) + ['lat' => '38.04', 'lng' => '-84.5'], 'pickup');
        $this->assertTrue($sig->account_charge);
        $this->assertCount(2, $sig->acknowledgments);
        $this->assertStringContainsString($this->ky->name, $sig->acknowledgments[1]);
        $this->assertSame($this->admin->id, $sig->user_id);
        $this->assertSame('38.0400000', (string) $sig->latitude);
        Storage::disk('local')->assertExists($sig->image_path);
        app(SalesOrderService::class)->deliver($so);
        $this->assertSame(S::Delivered, $so->fresh()->status);
    }

    public function test_driver_screen_delivers_with_signature_photo_and_notes(): void
    {
        $so = $this->order('TST-PIP-001');
        $m = $this->manifest([$so]);
        $stop = $m->stops->first();
        $this->actingAs($this->driver)->get(route('driver.today'))->assertOk()->assertSee($this->ky->name)->assertSee('Powered by DistroSKU');
        $lw = Livewire::actingAs($this->driver)->test(StopScreen::class, ['stop' => $stop])
            ->assertSee('I warrant that I am 21 years of age or older.')->assertSee('I acknowledge this charge to the account of')
            ->call('complete')->assertSet('ok', null);
        $this->assertSame('pending', $stop->fresh()->status);
        $lw->set('sigImage', self::PNG)->set('sigName', 'Pat Buyer')->set('sigAccepted', [true, true])->set('notes', 'Left at back door')
            ->set('photo', UploadedFile::fake()->image('proof.jpg'))->call('complete')->assertSet('err', null);
        $stop->refresh();
        $this->assertSame('delivered', $stop->status);
        $this->assertSame('Left at back door', $stop->notes);
        $this->assertNotNull($stop->photo_path);
        $this->assertSame('Pat Buyer', $stop->received_by);
        $this->assertSame(S::Delivered, $so->fresh()->status);
        $this->assertSame('delivery', DeliverySignature::where('sales_order_id', $so->id)->value('context'));
        // receipt PDF + order screen + invoice PDF show it
        $this->actingAs($this->admin)->get(route('docs.delivery-proof', $so))->assertOk()->assertHeader('content-type', 'application/pdf');
        $html = view('docs.delivery-proof', ['so' => $so->load('lines.product', 'customer', 'shipTo', 'signatures.user', 'deliveryStops.manifest.driver'),
            'photos' => [], 'company' => Company::current(), 'info' => 'x'])->render();
        $this->assertStringContainsString('Pat Buyer', $html);
        $this->assertStringContainsString('21 years of age', $html);
        $this->assertStringNotContainsString('Powered by DistroSKU', $html);
        $this->assertStringContainsString('data:image/png;base64', $html);
    }

    public function test_other_driver_cannot_open_stop_and_refused_only_records_status(): void
    {
        $so = $this->order('TST-PIP-001');
        $stop = $this->manifest([$so])->stops->first();
        $other = User::factory()->create(['role' => 'driver', 'active' => true]);
        $this->actingAs($other)->get(route('driver.stop', $stop))->assertForbidden();
        $this->actingAs(User::where('role', 'sales')->first())->get(route('driver.today'))->assertForbidden();
        app(DeliveryService::class)->complete($stop, 'refused', [], 'Store closed');
        $this->assertSame('refused', $stop->fresh()->status);
        $this->assertSame(S::Confirmed, $so->fresh()->status); // office re-delivers or cancels
        $this->assertSame(0, CreditMemo::count());
    }

    public function test_partial_delivery_reduces_lines_then_delivers(): void
    {
        $so = $this->order('TST-GEN-001', '5');
        $stop = $this->manifest([$so])->stops->first();
        $line = $so->lines()->first();
        $before = (float) Inventory::where('product_id', $line->product_id)->where('warehouse_id', $so->warehouse_id)->value('qty');
        app(DeliveryService::class)->complete($stop, 'partial', [$line->id => '3']);
        $this->assertSame('partial', $stop->fresh()->status);
        $this->assertSame(3.0, (float) $line->fresh()->qty);
        $this->assertSame(S::Delivered, $so->fresh()->status);
        $this->assertSame($before - 3, (float) Inventory::where('product_id', $line->product_id)->where('warehouse_id', $so->warehouse_id)->value('qty'));
    }

    public function test_route_planning_uses_delivery_days_and_route_sequence(): void
    {
        $day = DeliveryDays::key(now());
        $other = $day === 'mon' ? 'tue' : 'mon';
        $this->ky->update(['delivery_days' => $day]);
        $cash = Customer::where('code', 'CASH')->first();
        $cash->update(['delivery_days' => $other]);
        $c2 = Customer::create(['code' => 'C2', 'name' => 'Second Stop', 'terms' => 'Net 15']);
        ShipTo::create(['customer_id' => $c2->id, 'name' => 'S', 'state' => 'KY', 'route_sequence' => 1, 'delivery_days' => "$day,$other"]);
        CustomerLicense::create(['customer_id' => $c2->id, 'type' => 'tobacco_retail', 'number' => 'L2', 'state' => 'KY', 'expires_at' => now()->addYear()]);
        $this->kyShip->update(['route_sequence' => 2]);
        $a = $this->order('TST-GEN-001');
        $b = $this->order('TST-GEN-001', '1', $c2);
        $this->order('TST-GEN-001', '1', $cash); // other day: skipped
        $m = DeliveryManifest::create(['delivery_date' => now()->toDateString(), 'warehouse_id' => $a->warehouse_id, 'status' => 'open']);
        $this->assertSame(2, app(RoutePlanner::class)->plan($m));
        $this->assertSame([$b->id, $a->id], $m->stops()->pluck('sales_order_id')->all());
        $this->assertSame(0, app(RoutePlanner::class)->plan($m->fresh())); // not added twice
        $this->assertSame('mon,wed', DeliveryDays::toCsv(['wed', 'mon', 'xyz']));
    }

    public function test_van_sale_invoices_from_van_stock_with_signature_and_account_ack(): void
    {
        $van = Warehouse::create(['code' => 'VAN1', 'name' => 'Van 1', 'state' => 'KY', 'is_van' => true, 'van_driver_id' => $this->driver->id]);
        $p = Product::where('sku', 'TST-PIP-001')->first();
        app(InventoryService::class)->move($p, $van, '10', 'adjustment', null, $p->cost, 'load van');
        $this->actingAs($this->driver);
        $svc = app(VanSaleService::class);
        try {
            $svc->sell($van, $this->ky, $this->kyShip, [['product_id' => $p->id, 'qty' => 2]], 'account');
            $this->fail('expected signature block');
        } catch (WorkflowException) {
        }
        $this->assertSame(0, SalesOrder::where('channel', 'van')->count());
        try {
            $svc->sell($van, $this->ky, $this->kyShip, [['product_id' => $p->id, 'qty' => 2]], 'account', $this->sig(1));
            $this->fail('account ack missing');
        } catch (ValidationException) {
        }
        $so = $svc->sell($van, $this->ky, $this->kyShip, [['product_id' => $p->id, 'qty' => 2]], 'account', $this->sig(2));
        $this->assertSame(S::Invoiced, $so->status);
        $this->assertSame('van', $so->channel);
        $this->assertSame(8.0, (float) Inventory::where('product_id', $p->id)->where('warehouse_id', $van->id)->value('qty'));
        $this->assertSame('27.0000', (string) $so->total); // engine (list) price 13.50 x 2
        $this->assertTrue($so->signatures()->first()->account_charge);
        $this->assertSame('0.0000', (string) $so->amount_paid);
        $cash = $svc->sell($van, $this->ky, $this->kyShip, [['product_id' => $p->id, 'qty' => 1]], 'cash', $this->sig(1));
        $this->assertSame((string) $cash->total, (string) $cash->amount_paid);
        $this->assertFalse($cash->signatures()->first()->account_charge);
        // non-van warehouse refused; screen renders for driver
        $this->expectException(WorkflowException::class);
        $svc->sell(Warehouse::where('code', 'MAIN')->first(), $this->ky, $this->kyShip, [['product_id' => $p->id, 'qty' => 1]], 'cash');
    }

    public function test_van_sale_screen(): void
    {
        $van = Warehouse::create(['code' => 'VAN1', 'name' => 'Van 1', 'state' => 'KY', 'is_van' => true]);
        $p = Product::where('sku', 'TST-PIP-001')->first();
        app(InventoryService::class)->move($p, $van, '5', 'adjustment', null, $p->cost, 'load van');
        Livewire::actingAs($this->driver)->test(VanSale::class)->set('customerId', $this->ky->id)->set('scan', 'TST-PIP-001')->call('add')
            ->assertSee('I warrant that I am 21 years of age or older.')->set('tender', 'account')->assertSee('I acknowledge this charge')
            ->set('sigImage', self::PNG)->set('sigName', 'Pat Buyer')->set('sigAccepted', [true, true])->call('sell')->assertSet('err', null);
        $this->assertSame(1, SalesOrder::where('channel', 'van')->where('status', 'invoiced')->count());
        $this->actingAs(User::where('role', 'warehouse')->first())->get(route('driver.van'))->assertForbidden();
    }

    public function test_will_call_pickup_and_counter_pos_require_signature(): void
    {
        $so = $this->order('TST-PIP-001');
        $u = User::where('role', 'warehouse')->first();
        Livewire::actingAs($u)->test(PickupSign::class, ['so' => $so])->call('complete')->assertNotSet('err', null);
        $this->assertSame(S::Confirmed, $so->fresh()->status);
        Livewire::actingAs($u)->test(PickupSign::class, ['so' => $so])->set('sigImage', self::PNG)->set('sigName', 'Will Call')->set('sigAccepted', [true, true])
            ->call('complete')->assertSet('err', null);
        $this->assertSame(S::Delivered, $so->fresh()->status);
        $this->assertSame('pickup', $so->signatures()->value('context'));

        $sales = User::where('role', 'sales')->first();
        $pos = Livewire::actingAs($sales)->test(CounterPos::class)->set('scan', 'TST-PIP-001')->call('add')
            ->assertSee('I warrant that I am 21 years of age or older.')->call('checkout');
        $this->assertNotNull($pos->get('error'));
        $this->assertSame(0, SalesOrder::where('channel', 'pos')->count());
        $pos->set('sigImage', self::PNG)->set('sigName', 'Counter Buyer')->set('sigAccepted', [true])->call('checkout')->assertSet('error', null);
        $this->assertSame(1, SalesOrder::where('channel', 'pos')->count());
        $this->assertSame('counter', SalesOrder::where('channel', 'pos')->first()->signatures()->value('context'));
        // non-regulated counter sale: no pad
        Livewire::actingAs($sales)->test(CounterPos::class)->set('scan', 'TST-GEN-001')->call('add')->assertDontSee('21 years of age')->call('checkout')->assertSet('error', null);
    }

    public function test_modules_launcher_shows_role_tiles_and_sets_landing_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/modules')->assertOk()->assertSee('Sales Orders')->assertSee('Accounting')->assertSee('Settings')->assertSee('Powered by DistroSKU');
        $this->flushSession();
        $this->actingAs($this->driver);
        $labels = collect(Modules::tiles())->pluck('label');
        $this->assertContains('Driver app', $labels);
        $this->assertContains('Delivery', $labels);
        $this->assertNotContains('Accounting', $labels);
        $this->assertNotContains('Settings', $labels);
        $this->get('/admin/modules')->assertOk()->assertDontSee('Bills (AP)');
        Livewire::test(Modules::class)->call('toggleHome');
        $this->assertSame('modules', $this->driver->fresh()->landing_page);
        $resp = app(LoginResponse::class)->toResponse(request()->setUserResolver(fn () => $this->driver->fresh()));
        $this->assertStringEndsWith('/admin/modules', $resp->getTargetUrl());
    }

    public function test_admin_screens_render_and_migration_reruns(): void
    {
        $so = $this->order('TST-PIP-001');
        $this->actingAs($this->admin);
        app(SignatureService::class)->capture($so, $this->sig(2), 'pickup');
        $m = $this->manifest([]);
        foreach (['/admin/delivery-manifests', "/admin/delivery-manifests/{$m->id}/edit", '/admin/warehouses/create', '/admin/customers/create',
            '/admin/ship-tos/create', "/admin/sales-orders/{$so->id}/edit", '/admin/sales-orders'] as $u) {
            $this->get($u)->assertOk();
        }
        $this->get("/admin/sales-orders/{$so->id}/edit")->assertSee('Pat Buyer')->assertSee('I warrant that I am 21 years of age or older.');
        (include database_path('migrations/2026_11_04_000001_phase15_delivery_driver.php'))->up(); // re-run safe
        $this->assertTrue(Schema::hasTable('delivery_signatures'));
    }
}
