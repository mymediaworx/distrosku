<?php

namespace Tests\Feature;

use App\Delivery\VanSaleService;
use App\Filament\Resources\LotResource\Pages\Trace;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Lot;
use App\Models\LotContact;
use App\Models\LotMovement;
use App\Models\LotReviewItem;
use App\Models\LotStock;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\ProductCoa;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Pos\OfflineSaleSync;
use App\Pos\PosCheckout;
use App\Warehouse\Gs1;
use App\Warehouse\LotService;
use App\Warehouse\LotTracking;
use App\Warehouse\WarehouseService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.15 (#36): GS1 receiving, COA per lot, tracking by product type, lot on sale lines, Needs review, Trace. */
class Phase2015TraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $gen;

    private int $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
        $this->gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $this->gen->update(['track_lots' => true]);
        $this->main = (int) Warehouse::where('code', 'MAIN')->value('id');
    }

    private function lot(string $n, int $days, string $qty = '10', ?Product $p = null): Lot
    {
        return app(LotService::class)->receive($p ?? $this->gen, $this->main, $n, now()->addDays($days)->toDateString(), $qty);
    }

    private function pos(array $line): \App\Models\SalesOrder
    {
        return app(PosCheckout::class)->complete(['customer_id' => Customer::where('code', 'CASH')->value('id'), 'warehouse_id' => $this->main,
            'tender' => 'cash', 'lines' => [$line + ['product_id' => $this->gen->id, 'qty' => '2', 'price' => '25.00']]]);
    }

    private function stock(): float
    {
        return (float) Inventory::where('product_id', $this->gen->id)->where('warehouse_id', $this->main)->value('qty');
    }

    public function test_gs1_parse_human_and_raw_forms(): void
    {
        $this->assertSame(['gtin' => '00012345678905', 'lot' => 'AB12', 'expires' => '2027-03-31'], Gs1::parse('(01)00012345678905(17)270331(10)AB12'));
        $this->assertSame(['gtin' => '00012345678905', 'lot' => 'XY-9', 'expires' => '2027-02-28'], Gs1::parse("]C1010001234567890517270200\x1d10XY-9"));
        $this->assertSame('XY-9', Gs1::parse("010001234567890517270215\x1d10XY-9")['lot']);
        $this->assertSame('2027-02-15', Gs1::parse('01000123456789051727021510LOT7')['expires']);
        $this->assertSame('LOT7', Gs1::parse('01000123456789051727021510LOT7')['lot']);
        $this->assertNull(Gs1::parse('012345678905'));   // plain UPC stays a UPC
        $this->assertNull(Gs1::parse('ALT-PACK'));
    }

    public function test_gs1_receiving_fills_lot_and_expiry_track_lots_box_and_coa_link(): void
    {
        $p = Product::factory()->create(['sku' => 'HMP-GS1', 'upc' => '012345678905', 'price' => '5', 'product_subtype' => 'hemp']);
        $this->assertFalse((bool) $p->track_lots);
        $coa = ProductCoa::create(['product_id' => $p->id, 'lab' => 'Lab A', 'tested_on' => now()->subDays(5)]);
        $po = PurchaseOrder::create(['number' => 'PO-GS1', 'vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->main,
            'order_date' => now(), 'status' => 'ordered']);
        $po->lines()->create(['product_id' => $p->id, 'qty' => '10', 'unit_cost' => '2']);
        $svc = app(WarehouseService::class);
        $s = $svc->startReceiving($po->fresh(), $this->admin);
        $exp = now()->addYear()->format('ymd');
        $r = $svc->scanReceive($s, "(01)00012345678905(17){$exp}(10)G-1", 10, null, null, true, $coa->id);
        $this->assertSame('G-1', $r['lot']);
        $this->assertTrue((bool) $p->fresh()->track_lots);   // "track lots for this item" at receiving
        $svc->postReceiving($s);
        $lot = Lot::where('product_id', $p->id)->firstOrFail();
        $this->assertSame(now()->addYear()->toDateString(), $lot->expires_on->toDateString());
        $this->assertSame($lot->id, (int) $coa->fresh()->lot_id);
        $this->assertSame('G-1', $coa->fresh()->batch_lot);
        $this->assertFalse($lot->coaMissing());
        $this->assertEquals(10, (float) LotStock::where('lot_id', $lot->id)->value('qty'));
    }

    public function test_coa_missing_flags_hemp_lots_only(): void
    {
        $hemp = Product::where('sku', 'TST-HMP-001')->firstOrFail();
        $hl = $this->lot('H-1', 90, '5', $hemp);
        $this->assertTrue($hl->coaMissing());
        $this->assertFalse($this->lot('G-1', 90)->coaMissing());   // general merchandise never flags
        app(LotService::class)->attachCoa($hl, ProductCoa::create(['product_id' => $hemp->id, 'lab' => 'L']));
        $this->assertFalse($hl->fresh()->coaMissing());
        $h2 = $this->lot('H-2', 90, '5', $hemp);
        ProductCoa::create(['product_id' => $hemp->id, 'batch_lot' => 'H-2']);   // batch/lot text match also counts
        $this->assertFalse($h2->coaMissing());
    }

    public function test_tracking_by_department_and_tax_class_is_inherited_and_can_apply_to_existing(): void
    {
        $dep = Department::create(['name' => 'Hemp Dept']);
        $old = Product::factory()->create(['sku' => 'OLD-K', 'tax_class' => 'kratom', 'track_lots' => false]);
        LotTracking::save([$dep->id], ['kratom']);
        $this->assertTrue((bool) Product::factory()->create(['sku' => 'NEW-D', 'department_id' => $dep->id])->track_lots);
        $this->assertTrue((bool) Product::factory()->create(['sku' => 'NEW-K', 'tax_class' => 'kratom'])->track_lots);
        $this->assertFalse((bool) Product::factory()->create(['sku' => 'NEW-G', 'tax_class' => 'general'])->track_lots);
        $this->assertFalse((bool) $old->fresh()->track_lots);   // existing products unchanged until asked
        $this->assertGreaterThanOrEqual(1, LotTracking::applyToExisting());
        $this->assertTrue((bool) $old->fresh()->track_lots);

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(\App\Filament\Pages\Settings::class)->assertFormSet(['lot_track_tax_classes' => ['kratom']])
            ->set('data.lot_track_tax_classes', ['hemp'])->call('save')->assertHasNoErrors();
        $this->assertSame(['hemp'], LotTracking::taxClasses());
    }

    public function test_lots_on_hand_api_and_explicit_lot_is_honored_without_touching_stock_or_gl(): void
    {
        $early = $this->lot('E-1', 20);
        $late = $this->lot('L-1', 200);
        $held = $this->lot('H-1', 10);
        $held->update(['on_hold' => true]);
        $api = app(LotService::class)->lotsOnHand($this->gen, $this->main);
        $this->assertSame(['E-1', 'L-1'], array_column($api, 'lot_number'));   // FEFO, hold excluded
        $this->assertSame(['lot_id', 'lot_number', 'expires_on', 'qty', 'coa'], array_keys($api[0]));

        $before = $this->stock();
        $glBefore = JournalLine::count();
        $so = $this->pos(['lot_id' => $late->id]);
        $this->assertSame($late->id, (int) $so->lines->first()->lot_id);
        $this->assertEquals(8, (float) LotStock::where('lot_id', $late->id)->value('qty'));
        $this->assertEquals(10, (float) LotStock::where('lot_id', $early->id)->value('qty'));
        $this->assertSame($before - 2, $this->stock());   // inventory moves exactly as before
        $this->assertGreaterThan($glBefore, JournalLine::count());
        $this->assertSame(0, LotReviewItem::count());

        $this->expectExceptionMessage('on hold');
        $this->pos(['lot_id' => $held->id]);
    }

    public function test_single_lot_is_auto_assigned_several_or_none_go_to_needs_review(): void
    {
        $this->assertSame(0, LotReviewItem::count());
        $so0 = $this->pos([]);   // no lot stock at all: sale still completes
        $this->assertSame('invoiced', $so0->status->value);
        $this->assertSame('no_lot_stock', LotReviewItem::sole()->reason);

        $only = $this->lot('ONLY', 60);
        $so1 = $this->pos([]);
        $this->assertSame($only->id, (int) $so1->lines->first()->lot_id);
        $this->assertSame(1, LotReviewItem::count());

        $this->lot('SECOND', 90);
        $so2 = $this->pos([]);
        $item = LotReviewItem::where('sales_order_id', $so2->id)->sole();
        $this->assertSame('several_lots', $item->reason);
        $this->assertNull($so2->lines->first()->lot_id);

        $svc = app(LotService::class);
        try {
            $svc->resolveReview($item, null, $this->admin->id, '');
            $this->fail('resolved without lot or note');
        } catch (\App\Exceptions\WorkflowException) {
        }
        $svc->resolveReview($item, $only, $this->admin->id);
        $this->assertSame('resolved', $item->fresh()->status);
        $this->assertSame($only->id, (int) $so2->lines->first()->fresh()->lot_id);
        $this->assertEquals(6, (float) LotStock::where('lot_id', $only->id)->value('qty'));
        $this->assertSame(2, LotMovement::where('lot_id', $only->id)->where('type', 'sale')->count());

        $this->actingAs($this->admin)->get(\App\Filament\Resources\LotReviewItemResource::getUrl())->assertOk()->assertSee($so0->number);
    }

    public function test_offline_payload_lot_id_is_passed_through_and_bad_lot_is_kept_for_review(): void
    {
        $this->lot('A', 30);
        $b = $this->lot('B', 60);
        $cashier = User::factory()->create(['role' => 'sales']);
        $sale = fn (array $line) => ['uuid' => (string) Str::uuid(), 'user_id' => $cashier->id, 'sold_at' => now()->subHour()->toIso8601String(),
            'catalog_at' => now()->subHours(2)->toIso8601String(), 'customer_id' => Customer::where('code', 'CASH')->value('id'), 'warehouse_id' => $this->main,
            'fulfillment' => 'pickup', 'tender' => 'cash', 'total' => '50.00', 'lines' => [$line + ['product_id' => $this->gen->id, 'qty' => '2', 'price' => '25.00']]];
        $row = app(OfflineSaleSync::class)->ingest($sale(['lot_id' => $b->id]), $cashier, 'tab-1');
        $this->assertSame(OfflineSale::COMPLETED, $row->status);
        $this->assertSame($b->id, (int) $row->salesOrder->lines->first()->lot_id);

        $other = $this->lot('X', 30, '5', Product::where('sku', 'TST-HMP-002')->firstOrFail());
        $bad = app(OfflineSaleSync::class)->ingest($sale(['lot_id' => $other->id]), $cashier, 'tab-1');
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $bad->status);
        $this->assertStringContainsString('not a lot of', implode(' ', $bad->problems));
    }

    public function test_van_sale_honors_lot(): void
    {
        Warehouse::whereKey($this->main)->update(['is_van' => true]);
        $l = $this->lot('V-1', 30);
        $this->lot('V-2', 60);
        $c = Customer::where('code', 'CASH')->firstOrFail();
        $so = app(VanSaleService::class)->sell(Warehouse::find($this->main), $c, null, [['product_id' => $this->gen->id, 'qty' => '1', 'price' => '25', 'lot_id' => $l->id]], 'cash');
        $this->assertSame($l->id, (int) $so->lines->first()->lot_id);
        $this->assertSame(0, LotReviewItem::count());
    }

    public function test_trace_by_lot_sku_and_order_with_hold_csv_and_contact_log(): void
    {
        $l = $this->lot('T-1', 30);
        $so = $this->pos(['lot_id' => $l->id]);
        $svc = app(LotService::class);
        foreach (['T-1', 'TST-GEN-001', $so->number] as $q) {
            $t = $svc->trace($q);
            $this->assertSame(['T-1'], array_map(fn ($x) => $x['lot']->lot_number, $t['lots']), $q);
        }
        $this->assertCount(1, $svc->trace('T-1')['lots'][0]['sales']);

        $cust = (int) $so->customer_id;
        Livewire::test(Trace::class, ['q' => 'T-1'])->assertSee('T-1')->assertSee($so->number)
            ->call('toggleHold', $l->id)
            ->set("method.{$l->id}|{$cust}", 'phone')->set("note.{$l->id}|{$cust}", 'left message')->call('logContact', $l->id, $cust);
        $this->assertTrue($l->fresh()->on_hold);
        $c = LotContact::sole();
        $this->assertSame(['phone', $this->admin->id, 'left message'], [$c->method, (int) $c->user_id, $c->note]);

        $csv = $this->get(route('wh.trace.csv', ['q' => 'T-1']))->assertOk()->streamedContent();
        $this->assertStringContainsString('T-1', $csv);
        $this->assertStringContainsString('phone by', $csv);
        $this->get(Trace::getUrl(['q' => $so->number]))->assertOk();
        $this->assertSame([], $svc->trace('NOPE-404')['lots']);
    }
}
