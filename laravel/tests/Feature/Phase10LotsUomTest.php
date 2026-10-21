<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Livewire\Warehouse;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\Lot;
use App\Models\LotStock;
use App\Models\PickList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WarehouseEvent;
use App\Models\Warehouse as WH;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use App\Warehouse\LotService;
use App\Warehouse\ProductLookup;
use App\Warehouse\Uom;
use App\Warehouse\WarehouseService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase10LotsUomTest extends TestCase
{
    use RefreshDatabase;

    private WH $wh;

    private User $picker;

    private User $admin;

    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = WH::where('code', 'MAIN')->first();
        $this->picker = User::factory()->create(['role' => 'warehouse']);
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'mgr@example.test', 'password' => bcrypt('secret-pw')]);
        $this->p = Product::factory()->create(['sku' => 'LT-1', 'upc' => '012345678905', 'price' => '5', 'track_lots' => true, 'base_unit_name' => 'Pack']);
        $ctn = $this->p->units()->create(['code' => 'CTN', 'name' => 'Carton', 'factor' => 10]);
        $cs = $this->p->units()->create(['code' => 'CS', 'name' => 'Case', 'factor' => 50]);
        $this->p->barcodes()->create(['code' => '10012345678902', 'product_unit_id' => $cs->id]);
        $this->p->barcodes()->create(['code' => '20012345678909', 'product_unit_id' => $ctn->id]);
        $this->p->barcodes()->create(['code' => 'ALT-PACK']);
    }

    private function po(string $qty = '100', ?string $pct = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(['number' => 'PO-L'.uniqid(), 'vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->wh->id,
            'order_date' => now(), 'status' => 'ordered', 'over_receipt_pct' => $pct]);
        $po->lines()->create(['product_id' => $this->p->id, 'qty' => $qty, 'unit_cost' => '2']);

        return $po->fresh();
    }

    private function so(string $qty): SalesOrder
    {
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['number' => 'SO-L'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $this->p->id, 'qty' => $qty, 'unit_price' => '5']);

        return app(SalesOrderService::class)->confirm($so->fresh());
    }

    /** Receive two lots: L-LATE (exp +200d) 50 via a case scan, L-EARLY (exp +20d) 50 via carton scans. */
    private function receiveTwoLots(): void
    {
        $svc = app(WarehouseService::class);
        $s = $svc->startReceiving($this->po(), $this->picker);
        $svc->scanReceive($s, '10012345678902', 1, 'L-LATE', now()->addDays(200)->toDateString());
        $svc->scanReceive($s, '20012345678909', 5, 'L-EARLY', now()->addDays(20)->toDateString());
        $svc->postReceiving($s);
    }

    public function test_uom_lookup_conversion_and_describe(): void
    {
        $r = ProductLookup::resolve('10012345678902');
        $this->assertSame($this->p->id, $r['product']->id);
        $this->assertSame('50', rtrim(rtrim($r['factor'], '0'), '.'));
        $this->assertSame('1', ProductLookup::resolve('ALT-PACK')['factor']);
        $this->assertSame('1', ProductLookup::resolve('012345678905')['factor']);
        $this->assertSame('1 CS + 2 CTN + 3 Pack', Uom::describe($this->p, 73));
        $this->assertSame('100.0000', Uom::toBase($this->p, 2, 'CS'));
        $this->assertSame($this->p->id, ProductLookup::takenBy('ALT-PACK')?->id);
    }

    public function test_lot_receiving_requires_lot_and_tracks_stock(): void
    {
        $svc = app(WarehouseService::class);
        $s = $svc->startReceiving($this->po(), $this->picker);
        $this->expectExceptionMessage('lot-tracked');
        $svc->scanReceive($s, '012345678905', 1);
    }

    public function test_expired_lot_refused_and_stock_split_by_lot(): void
    {
        $svc = app(WarehouseService::class);
        $s = $svc->startReceiving($this->po(), $this->picker);
        try {
            $svc->scanReceive($s, '012345678905', 1, 'OLD', now()->subDay()->toDateString());
            $this->fail('expired accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        $this->receiveTwoLots();
        $this->assertEquals(50, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'L-LATE'))->value('qty'));
        $this->assertEquals(50, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'L-EARLY'))->value('qty'));
        $this->assertEquals(100, (float) $this->p->inventories()->sum('qty'));
    }

    public function test_fefo_allocation_enforced_on_pick_and_recall(): void
    {
        $this->receiveTwoLots();
        $so = $this->so('60');
        $svc = app(WarehouseService::class);
        $pl = $svc->pickList($so);
        $line = $pl->lines()->first();
        $alloc = $line->lots()->with('lot')->get()->mapWithKeys(fn ($a) => [$a->lot->lot_number => (float) $a->qty_allocated])->all();
        ksort($alloc);
        $this->assertSame(['L-EARLY' => 50.0, 'L-LATE' => 10.0], $alloc);

        try {
            $svc->scanPick($pl, '20012345678909', 1, 'L-LATE');
            $this->fail('FEFO not enforced');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('FEFO', $e->getMessage());
        }
        try {
            $svc->scanPick($pl, '20012345678909', 1);
            $this->fail('lot not required');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('L-EARLY', $e->getMessage());
        }
        $svc->scanPick($pl, '20012345678909', 5, 'L-EARLY'); // 5 cartons = 50
        $svc->scanPick($pl, '20012345678909', 1, 'l-late');  // case-insensitive lot
        $this->assertEquals(60, (float) $line->fresh()->qty_picked);
        $svc->completePick($pl, $this->picker);
        app(SalesOrderService::class)->deliver($so->fresh());

        $this->assertEquals(0, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'L-EARLY'))->value('qty'));
        $this->assertEquals(40, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'L-LATE'))->value('qty'));

        $rep = app(LotService::class)->recall('L-LATE');
        $this->assertCount(1, $rep['rows']);
        $this->assertSame($so->number, $rep['rows'][0][4]);
        $this->assertSame('10', rtrim(rtrim($rep['rows'][0][8], '0'), '.'));
        $this->assertStringContainsString($so->number, $this->actingAs($this->admin)->get(route('wh.recall.csv', ['lot' => 'L-LATE']))->assertOk()->streamedContent());
        $this->actingAs($this->admin)->get('/admin/lots/recall?lot=L-LATE')->assertOk()->assertSee($so->number);
        $this->actingAs($this->admin)->get('/admin/lots')->assertOk()->assertSee('L-LATE');
    }

    public function test_held_and_expired_lots_not_allocated(): void
    {
        $this->receiveTwoLots();
        Lot::where('lot_number', 'L-EARLY')->update(['on_hold' => true]);
        $pl = app(WarehouseService::class)->pickList($this->so('10'));
        $this->assertSame(['L-LATE'], $pl->lines()->first()->lots()->with('lot')->get()->pluck('lot.lot_number')->all());
    }

    public function test_deliver_without_scan_uses_fefo(): void
    {
        $this->receiveTwoLots();
        $so = $this->so('55');
        app(SalesOrderService::class)->deliver($so);
        $this->assertEquals(0, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'L-EARLY'))->value('qty'));
        $this->assertEquals(45, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'L-LATE'))->value('qty'));
    }

    public function test_short_pick_needs_admin_approval_reduces_order_and_logs(): void
    {
        $this->receiveTwoLots();
        $so = $this->so('20');
        $svc = app(WarehouseService::class);
        $pl = $svc->pickList($so);
        $line = $pl->lines()->first();
        $svc->scanPick($pl, '012345678905', 12, 'L-EARLY');
        try {
            $svc->shortPick($line, $this->picker, $this->picker, 'not on shelf');
            $this->fail('picker approved own short pick');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('manager or admin', $e->getMessage());
        }
        $svc->shortPick($line->fresh(), $this->picker, $this->admin, 'not on shelf');
        $this->assertEquals(12, (float) $so->lines()->first()->qty);
        $this->assertEquals(60, (float) $so->fresh()->total);
        $this->assertEquals(12, (float) $this->p->inventories()->first()->reserved);
        $ev = WarehouseEvent::where('type', 'short_pick')->first();
        $this->assertEquals(8, (float) $ev->qty);
        $this->assertSame($this->admin->id, $ev->approved_by);
        $this->assertEquals(12, (float) $line->lots()->sum('qty_allocated'));
        $svc->completePick($pl->fresh(), $this->picker);
        $this->assertSame('picked', $pl->fresh()->status);
    }

    public function test_short_pick_ui_with_manager_credentials(): void
    {
        $this->receiveTwoLots();
        $so = $this->so('20');
        $this->actingAs($this->picker);
        $c = Livewire::test(Warehouse\Pick::class, ['so' => $so]);
        $lineId = PickList::where('sales_order_id', $so->id)->first()->lines()->value('id');
        $c->call('startShort', $lineId)->set('shortQty', '5')->set('shortReason', 'damaged')
            ->set('approverEmail', 'mgr@example.test')->set('approverPassword', 'wrong')->call('shortPick')->assertSee('approval failed');
        $c->set('approverPassword', 'secret-pw')->call('shortPick')->assertSee('approved by');
        $this->assertEquals(5, (float) $so->lines()->first()->qty);
        $this->assertSame(1, WarehouseEvent::where('type', 'short_pick')->count());
    }

    public function test_over_receipt_allowance_per_po_and_global_logged(): void
    {
        $svc = app(WarehouseService::class);
        // default: refused
        $s = $svc->startReceiving($this->po('10'), $this->picker);
        try {
            $svc->scanReceive($s, '012345678905', 11, 'A1', now()->addYear()->toDateString());
            $this->fail('over-receipt accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Over-receipt', $e->getMessage());
        }
        // per-PO 10%
        $po = $this->po('10', '10');
        $s = $svc->startReceiving($po, $this->picker);
        $r = $svc->scanReceive($s, '012345678905', 11, 'A1', now()->addYear()->toDateString());
        $this->assertSame('1', rtrim(rtrim($r['over'], '0'), '.'));
        $this->expectsOverAfter(fn () => $svc->scanReceive($s, '012345678905', 1, 'A1', now()->addYear()->toDateString()));
        $svc->postReceiving($s);
        $this->assertSame('received', $po->fresh()->status);
        $ev = WarehouseEvent::where('type', 'over_receipt')->first();
        $this->assertEquals(1, (float) $ev->qty);
        $this->assertSame($po->id, $ev->purchase_order_id);
        // global 20%
        Setting::put('over_receipt_pct', '20');
        $po2 = $this->po('10');
        app(PurchaseOrderService::class)->receive($po2, [$po2->lines->first()->id => 12]);
        $this->assertSame(2, WarehouseEvent::where('type', 'over_receipt')->count());
        $this->expectException(WorkflowException::class);
        app(PurchaseOrderService::class)->receive($po2->fresh(), [$po2->lines->first()->id => 1]);
    }

    private function expectsOverAfter(callable $f): void
    {
        try {
            $f();
            $this->fail('beyond allowance accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }
    }

    public function test_bin_labels_print_without_footer(): void
    {
        $b = Bin::create(['warehouse_id' => $this->wh->id, 'code' => 'A-01']);
        Bin::create(['warehouse_id' => $this->wh->id, 'code' => 'B-02']);
        $html = $this->actingAs($this->picker)->get(route('wh.print.bins'))->assertOk()->assertSee('A-01')->assertSee('B-02')->getContent();
        $this->assertStringNotContainsStringIgnoringCase('powered by', $html);
        $this->assertStringContainsString('<svg', $html);
        $pdf = $this->get(route('wh.print.bins', ['ids' => $b->id, 'format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $u = User::factory()->create(['role' => 'sales']);
        $this->actingAs($u)->get(route('wh.print.bins'))->assertForbidden();
    }

    public function test_pick_list_print_shows_units_and_lots(): void
    {
        $this->receiveTwoLots();
        $so = $this->so('73');
        $html = $this->actingAs($this->picker)->get(route('wh.print.pick', $so))->assertOk()->getContent();
        $this->assertStringContainsString('1 CS + 2 CTN + 3 Pack', $html);
        $this->assertStringContainsString('L-EARLY', $html);
        $this->assertStringNotContainsStringIgnoringCase('powered by', $html);
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/warehouse-events')->assertOk();
        $this->get('/admin/products/'.$this->p->id.'/edit')->assertOk()->assertSee('Track lots');
        $this->get('/admin/bins')->assertOk();
        $this->get('/admin/purchase-orders/create')->assertOk()->assertSee('Over-receipt allowance');
    }
}
