<?php

namespace Tests\Feature;

use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Livewire\Warehouse;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\PickList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse as WH;
use App\Services\SalesOrderService;
use App\Warehouse\ProductLookup;
use App\Warehouse\WarehouseService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase9WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private WH $wh;

    private User $picker;

    private Product $a;

    private Product $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = WH::where('code', 'MAIN')->first();
        $this->picker = User::factory()->create(['role' => 'warehouse']);
        $this->a = Product::factory()->create(['sku' => 'WA-1', 'upc' => '012345678905', 'price' => '5']);
        $this->b = Product::factory()->create(['sku' => 'WB-2', 'upc' => '098765432109', 'price' => '3']);
        Bin::create(['warehouse_id' => $this->wh->id, 'code' => 'B-02', 'pick_sequence' => 2]);
        Bin::create(['warehouse_id' => $this->wh->id, 'code' => 'A-01', 'pick_sequence' => 1]);
    }

    private function po(): PurchaseOrder
    {
        $po = PurchaseOrder::create(['number' => 'PO-W'.uniqid(), 'vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->wh->id, 'order_date' => now(), 'status' => 'ordered']);
        $po->lines()->create(['product_id' => $this->a->id, 'qty' => '5', 'unit_cost' => '2']);
        $po->lines()->create(['product_id' => $this->b->id, 'qty' => '3', 'unit_cost' => '1']);

        return $po->fresh();
    }

    private function receiveAll(): void
    {
        $svc = app(WarehouseService::class);
        $s = $svc->startReceiving($this->po(), $this->picker);
        $svc->scanReceive($s, '012345678905', 5);
        $svc->scanReceive($s, 'wb-2', 3);
        $svc->postReceiving($s);
        $svc->putaway(PutawayTask::where('product_id', $this->a->id)->first(), 'B-02', 'WA-1');
        $svc->putaway(PutawayTask::where('product_id', $this->b->id)->first(), 'A-01', '098765432109');
    }

    private function confirmedOrder(): SalesOrder
    {
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['number' => 'SO-W'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $this->a->id, 'qty' => '2', 'unit_price' => '5']);
        $so->lines()->create(['product_id' => $this->b->id, 'qty' => '1', 'unit_price' => '3']);

        return app(SalesOrderService::class)->confirm($so->fresh());
    }

    public function test_lookup_by_upc_sku_and_upc_variants(): void
    {
        $this->assertSame($this->a->id, ProductLookup::find('012345678905')->id);
        $this->assertSame($this->a->id, ProductLookup::find('0012345678905')->id); // EAN-13 form
        $this->assertSame($this->b->id, ProductLookup::find(' wb-2 ')->id);
        $this->assertNull(ProductLookup::find('nope'));
    }

    public function test_scan_receiving_refuses_wrong_and_over_and_posts_partial_with_variance(): void
    {
        $svc = app(WarehouseService::class);
        $po = $this->po();
        $s = $svc->startReceiving($po, $this->picker);
        $this->assertSame($s->id, $svc->startReceiving($po, $this->picker)->id); // resumes
        $svc->scanReceive($s, 'WA-1', 4);
        foreach ([['WA-1', 2, 'Over-receipt'], [Product::factory()->create()->sku, 1, 'not on PO'], ['zzz', 1, 'Unknown']] as [$code, $q, $msg]) {
            try {
                $svc->scanReceive($s, $code, $q);
                $this->fail("accepted $code");
            } catch (WorkflowException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $svc->scanReceive($s, 'WB-2', 1);
        $svc->undoLastScan($s);
        $s = $svc->postReceiving($s);
        $this->assertSame('posted', $s->status);
        $this->assertSame('partial', $po->fresh()->status);
        $this->assertSame('4.0000', Inventory::where('product_id', $this->a->id)->value('qty'));
        $this->assertNull(Inventory::where('product_id', $this->b->id)->value('qty'));
        $this->assertCount(2, $s->variances);
        $this->assertSame(1, PutawayTask::count());
        $this->assertSame('-8.0000', \App\Models\JournalLine::whereHas('account', fn ($q) => $q->where('code', '2050'))->get()->reduce(fn ($c, $l) => bcadd($c, bcsub($l->debit, $l->credit, 4), 4), '0.0000'));
    }

    public function test_putaway_validates_item_and_bin_and_sets_primary_bin(): void
    {
        $svc = app(WarehouseService::class);
        $s = $svc->startReceiving($this->po(), $this->picker);
        $svc->scanReceive($s, 'WA-1', 5);
        $svc->postReceiving($s);
        $t = PutawayTask::first();
        foreach ([['B-02', 'WB-2', 'Wrong item'], ['Z-99', 'WA-1', 'not found']] as [$bin, $code, $msg]) {
            try {
                $svc->putaway($t, $bin, $code);
                $this->fail('accepted');
            } catch (WorkflowException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $svc->putaway($t, 'B-02', 'WA-1', $this->picker);
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame('B-02', Inventory::where('product_id', $this->a->id)->first()->bin->code);
    }

    public function test_pick_scan_to_confirm_then_pack_and_print(): void
    {
        $this->receiveAll();
        $so = $this->confirmedOrder();
        $svc = app(WarehouseService::class);
        $pl = $svc->pickList($so);
        $this->assertSame($pl->id, $svc->pickList($so)->id);
        $this->assertSame(['A-01', 'B-02'], $svc->orderedLines($pl)->map(fn ($l) => $l->bin->code)->all()); // walk order
        try {
            $svc->completePick($pl);
            $this->fail('completed unpicked');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Not fully picked', $e->getMessage());
        }
        $svc->scanPick($pl, 'WA-1');
        $svc->scanPick($pl, '012345678905');
        foreach ([['WA-1', 'Over-pick'], [Product::factory()->create()->sku, 'Wrong item']] as [$code, $msg]) {
            try {
                $svc->scanPick($pl, $code);
                $this->fail('accepted');
            } catch (WorkflowException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $svc->scanPick($pl, 'WB-2');
        $svc->completePick($pl->fresh(), $this->picker);
        $this->assertSame(S::Picked, $so->fresh()->status);

        $svc->scanPack($pl->fresh(), 'WA-1', 2);
        $svc->closePackage($pl->fresh(), '4.5');
        $svc->scanPack($pl->fresh(), 'WB-2');
        try {
            $svc->scanPack($pl->fresh(), 'WB-2');
            $this->fail('over-packed');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('fully packed', $e->getMessage());
        }
        $svc->completePack($pl->fresh());
        $this->assertSame('packed', $pl->fresh()->status);
        $this->assertSame(2, $pl->packages()->count());

        app(SalesOrderService::class)->deliver($so->fresh()); // picked orders still deliver normally
        $this->assertSame('3.0000', Inventory::where('product_id', $this->a->id)->value('qty'));

        $this->actingAs($this->picker);
        $this->get(route('wh.print.pick', $so))->assertOk()->assertSee($pl->number)->assertSee('A-01')->assertDontSee('Powered by DistroSKU')->assertDontSee('distrosku-powered');
        $this->get(route('wh.print.pack', $so))->assertOk()->assertSee('Carton 2 of 2')->assertSee('4.50 lb')->assertDontSee('Powered by DistroSKU');
        $pdf = $this->get(route('wh.print.pack', $so).'?format=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_livewire_screens(): void
    {
        $this->actingAs($this->picker);
        $po = $this->po();
        $this->get('/warehouse')->assertOk()->assertSee($po->number);
        Livewire::test(Warehouse\Receive::class, ['po' => $po])
            ->set('scan', 'WA-1')->set('qty', '5')->call('add')->assertSee('0 left')
            ->set('scan', 'WB-2')->set('qty', '3')->call('add')
            ->set('scan', 'WB-2')->call('add')->assertSee('Over-receipt')
            ->call('post')->assertSee('Received and posted');
        $this->assertSame('received', $po->fresh()->status);
        foreach (PutawayTask::all() as $t) {
            Livewire::test(Warehouse\Putaway::class)->call('choose', $t->id)->set('bin', 'A-01')->set('scan', $t->product->sku)->call('add')->assertSee('Put away');
        }
        $so = $this->confirmedOrder();
        Livewire::test(Warehouse\Pick::class, ['so' => $so])->set('scan', 'WA-1')->set('qty', '2')->call('add')
            ->set('scan', 'WB-2')->call('add')->call('complete')->assertSee('Pick complete');
        Livewire::test(Warehouse\Pack::class, ['so' => $so])->set('scan', 'WA-1')->set('qty', '2')->call('add')->set('scan', 'WB-2')->call('add')
            ->call('complete')->assertSee('Packing complete');
        $this->get('/warehouse/pick/'.$so->id)->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->get('/warehouse')->assertForbidden();
        $this->get('/warehouse/receive/'.$po->id)->assertForbidden();
        $this->get(route('wh.print.pick', $so))->assertOk(); // sales can print lists for their orders

        $this->actingAs(User::factory()->create(['role' => 'driver']));
        $this->get(route('wh.print.pick', $so))->assertForbidden();
    }

    public function test_admin_bins_and_order_actions_render(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/bins')->assertOk()->assertSee('A-01');
        $this->get('/admin/sales-orders')->assertOk();
        $this->get('/admin/purchase-orders')->assertOk();
        $this->get('/admin')->assertOk()->assertSee('Warehouse scanner');
    }
}
