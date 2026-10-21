<?php

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Exceptions\WorkflowException;
use App\Inventory\CountService;
use App\Inventory\ReorderService;
use App\Livewire\Warehouse;
use App\Models\Account;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryCount;
use App\Models\JournalLine;
use App\Models\LotStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse as WH;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Warehouse\LotService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase11CountsReorderTest extends TestCase
{
    use RefreshDatabase;

    private WH $wh;

    private User $counter;

    private User $admin;

    private Product $a;

    private Product $lotted;

    private Bin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        // Phase 13: counts auto-approve within $25 / 2% by default; these phase 11 tests exercise the manual admin approval path.
        \App\Models\Setting::put('count_auto_approve_value', '0');
        \App\Models\Setting::put('count_auto_approve_pct', '0');
        $this->wh = WH::where('code', 'MAIN')->first();
        $this->counter = User::factory()->create(['role' => 'warehouse']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->bin = Bin::create(['warehouse_id' => $this->wh->id, 'code' => 'A-01']);
        $this->a = Product::factory()->create(['sku' => 'CA-1', 'upc' => '012345678905', 'cost' => '2', 'price' => '5']);
        $cs = $this->a->units()->create(['code' => 'CS', 'name' => 'Case', 'factor' => 10]);
        $this->a->barcodes()->create(['code' => 'CASE-CA1', 'product_unit_id' => $cs->id]);
        $this->lotted = Product::factory()->create(['sku' => 'CL-2', 'cost' => '3', 'price' => '6', 'track_lots' => true]);
        $inv = app(InventoryService::class);
        $inv->move($this->a, $this->wh, 50, 'receipt');
        Inventory::where('product_id', $this->a->id)->update(['bin_id' => $this->bin->id]);
        $inv->move($this->lotted, $this->wh, 30, 'receipt');
        app(LotService::class)->receive($this->lotted, $this->wh->id, 'LOT-A', now()->addMonths(6)->toDateString(), 20);
    }

    private function shrinkNet(): float
    {
        $id = Account::where('code', '5200')->value('id');

        return (float) JournalLine::where('account_id', $id)->sum('debit') - (float) JournalLine::where('account_id', $id)->sum('credit');
    }

    public function test_cycle_count_freezes_scans_uom_and_posts_loss_to_gl(): void
    {
        $svc = app(CountService::class);
        $c = $svc->create($this->wh, 'cycle', true, ['bin_ids' => [$this->bin->id]], $this->admin);
        $svc->start($c);
        $this->assertSame(1, $c->lines()->count());
        $this->assertEquals(50, (float) $c->lines()->first()->expected);

        // frozen: no stock movement while counting
        try {
            app(InventoryService::class)->move($this->a, $this->wh, -1, 'sale');
            $this->fail('frozen item moved');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }
        // lotted item not in scope: not frozen
        app(InventoryService::class)->move($this->lotted, $this->wh, 1, 'receipt');

        $svc->scan($c, 'CASE-CA1', 4, null, null, $this->counter);   // 40
        $svc->scan($c, '012345678905', 6, null, null, $this->counter); // 46
        $this->assertEquals(46, (float) $c->lines()->first()->counted);
        try {
            $svc->scan($c, 'CL-2', 1);
            $this->fail('out-of-scope item counted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('not in this cycle count', $e->getMessage());
        }
        $svc->submit($c, false, $this->counter);
        $this->assertEquals(-8, (float) $c->fresh()->variance_value);
        try {
            $svc->approve($c->fresh(), $this->counter);
            $this->fail('counter approved');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('owner or admin', $e->getMessage());
        }
        $svc->approve($c->fresh(), $this->admin);
        $this->assertSame('posted', $c->fresh()->status);
        $this->assertEquals(46, (float) Inventory::where('product_id', $this->a->id)->value('qty'));
        $this->assertEquals(8, $this->shrinkNet());
        $this->assertNotNull($c->fresh()->journal_id);
        // unfrozen
        app(InventoryService::class)->move($this->a, $this->wh, -1, 'sale');
    }

    public function test_lot_aware_full_count_with_gain_new_lot_and_zeroed_uncounted(): void
    {
        $svc = app(CountService::class);
        $c = $svc->create($this->wh, 'full', false, [], $this->admin);
        $svc->start($c);
        // CA-1 (50) + CL-2 LOT-A (20) + CL-2 unlotted (10)
        $this->assertSame(3, $c->lines()->count());
        try {
            $svc->scan($c, 'CL-2', 1);
            $this->fail('lot not required');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('lot', $e->getMessage());
        }
        $svc->scan($c, 'CL-2', 22, 'LOT-A');
        $svc->scan($c, 'CL-2', 5, 'LOT-NEW', now()->addYear()->toDateString()); // found stock, new lot
        $svc->undoLastScan($c);
        $svc->scan($c, 'CL-2', 4, 'LOT-NEW', now()->addYear()->toDateString());
        $line = $c->lines()->where('product_id', $this->a->id)->first();
        $svc->setCounted($line, 50);
        try {
            $svc->submit($c);
            $this->fail('uncounted submitted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Not counted', $e->getMessage());
        }
        $svc->submit($c, true, $this->counter); // unlotted CL-2 line -> 0 (expected 10)
        $svc->approve($c->fresh(), $this->admin);
        // CL-2: 22 + 4 + 0 = 26 (was 30)
        $this->assertEquals(26, (float) Inventory::where('product_id', $this->lotted->id)->value('qty'));
        $this->assertEquals(22, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'LOT-A'))->value('qty'));
        $this->assertEquals(4, (float) LotStock::whereHas('lot', fn ($q) => $q->where('lot_number', 'LOT-NEW'))->value('qty'));
        // gains 2*3 + 4*3 = 18, losses 10*3 = 30 -> net 12 shrinkage
        $this->assertEquals(12, $this->shrinkNet());
        $this->assertEquals(-12, (float) $c->fresh()->variance_value);
    }

    public function test_full_count_blocks_other_counts_and_cancel_unfreezes(): void
    {
        $svc = app(CountService::class);
        $c = $svc->create($this->wh, 'full', true, [], $this->admin);
        $svc->start($c);
        $c2 = $svc->create($this->wh, 'cycle', false, ['product_ids' => [$this->a->id]], $this->admin);
        try {
            $svc->start($c2);
            $this->fail('double freeze');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('already frozen', $e->getMessage());
        }
        try {
            $svc->cancel($c, $this->counter);
            $this->fail('counter cancelled');
        } catch (WorkflowException) {
        }
        $svc->cancel($c, $this->admin);
        app(InventoryService::class)->move($this->a, $this->wh, -1, 'sale');
        $this->assertEquals(49, (float) Inventory::where('product_id', $this->a->id)->value('qty'));
    }

    public function test_no_variance_posts_no_journal_and_reopen(): void
    {
        $svc = app(CountService::class);
        $c = $svc->create($this->wh, 'cycle', false, ['product_ids' => [$this->a->id]], $this->admin);
        $svc->start($c);
        $svc->scan($c, 'CASE-CA1', 5);
        $svc->submit($c);
        $svc->reopen($c->fresh(), $this->admin);
        $this->assertSame('counting', $c->fresh()->status);
        $svc->submit($c->fresh());
        $svc->approve($c->fresh(), $this->admin);
        $this->assertNull($c->fresh()->journal_id);
    }

    public function test_blind_count_scanner_hides_expected_and_sheet_has_no_footer(): void
    {
        $svc = app(CountService::class);
        $c = $svc->create($this->wh, 'cycle', true, ['product_ids' => [$this->a->id]], $this->admin);
        $svc->start($c);
        $this->actingAs($this->counter);
        Livewire::test(Warehouse\Count::class, ['count' => $c])->assertDontSee('Expected')
            ->set('scan', 'CASE-CA1')->set('qty', '2')->call('add')->assertSee('total 20')
            ->set('zero', true)->call('submit')->assertSee('Submitted');
        $html = $this->get(route('wh.print.count', $c))->assertOk()->assertDontSee('Expected')->getContent();
        $this->assertStringNotContainsStringIgnoringCase('powered by', $html);
        $this->actingAs($this->admin)->get(route('wh.print.count', $c))->assertOk()->assertSee('Expected');
        $this->assertStringStartsWith('%PDF', $this->get(route('wh.print.count', ['count' => $c, 'format' => 'pdf']))->getContent());
        $this->get('/admin/inventory-counts')->assertOk()->assertSee($c->number);
        $this->get('/admin/inventory-counts/'.$c->id)->assertOk();
        Livewire::test(\App\Filament\Resources\InventoryCountResource\RelationManagers\LinesRelationManager::class,
            ['ownerRecord' => $c->fresh(), 'pageClass' => \App\Filament\Resources\InventoryCountResource\Pages\ViewInventoryCount::class])
            ->assertSee('CA-1')->assertSee('Variance');
        $this->get('/admin/inventory-counts/create')->assertOk();
        $this->actingAs($this->counter)->get('/warehouse')->assertOk();
    }

    public function test_reorder_suggestions_and_draft_pos_per_vendor(): void
    {
        $v1 = Vendor::factory()->create(['name' => 'Alpha', 'lead_time_days' => 10]);
        $v2 = Vendor::factory()->create(['name' => 'Beta']);
        // CA-1: 50 on hand; sold 60 in last 30 days -> 2/day; lead 10 -> level 20... make it need stock:
        $this->a->update(['preferred_vendor_id' => $v1->id, 'reorder_qty' => 10]);
        for ($i = 0; $i < 3; $i++) {
            app(InventoryService::class)->move($this->a, $this->wh, -10, 'sale'); // 30 sold -> 1/day, on hand 20
        }
        // level = max(rp 0, 1*10) = 10; position 20 -> no suggestion yet
        $this->assertEmpty(collect(app(ReorderService::class)->suggestions($this->wh->id))->where('product.id', $this->a->id));
        app(InventoryService::class)->move($this->a, $this->wh, -12, 'sale'); // 42 sold -> 1.4/day, on hand 8
        $r = collect(app(ReorderService::class)->suggestions($this->wh->id))->firstWhere('product.id', $this->a->id);
        $this->assertNotNull($r);
        $this->assertSame('Alpha', $r['vendor']->name);
        // level = ceil(1.4*10)=14; target 14 + 1.4*14=33.6 -> 34 - 8 = 26 -> multiple of 10 -> 30
        $this->assertEquals(30, (float) $r['qty']);

        // CL-2 via reorder point and last-PO vendor, with on-order counted
        $this->lotted->update(['reorder_point' => 40]);
        $po = PurchaseOrder::create(['vendor_id' => $v2->id, 'warehouse_id' => $this->wh->id, 'order_date' => now(), 'status' => 'ordered']);
        $po->lines()->create(['product_id' => $this->lotted->id, 'qty' => 5, 'unit_cost' => 3]);
        $r2 = collect(app(ReorderService::class)->suggestions($this->wh->id))->firstWhere('product.id', $this->lotted->id);
        $this->assertSame('Beta', $r2['vendor']->name);
        $this->assertEquals(35, (float) $r2['position']); // 30 + 5 on order
        $this->assertEquals(5, (float) $r2['qty']);

        $this->actingAs($this->admin);
        Livewire::test(\App\Filament\Pages\ReorderSuggestions::class)->assertSee('CA-1')->assertSee('Beta')->call('createPo', $v1->id);
        $draft = PurchaseOrder::where('vendor_id', $v1->id)->where('status', 'draft')->first();
        $this->assertNotNull($draft);
        $this->assertEquals(30, (float) $draft->lines()->first()->qty);
        // now on order: suggestion disappears
        $this->assertNull(collect(app(ReorderService::class)->suggestions($this->wh->id))->firstWhere('product.id', $this->a->id));
        Livewire::test(\App\Filament\Pages\ReorderSuggestions::class)->call('createPo');
        $this->assertSame(1, PurchaseOrder::where('vendor_id', $v2->id)->where('status', 'draft')->count());
        $this->actingAs($this->counter);
        Livewire::test(\App\Filament\Pages\ReorderSuggestions::class)->assertDontSee('Create draft PO');
    }

    public function test_settings_and_product_pages(): void
    {
        $this->actingAs($this->admin)->get('/admin/products/'.$this->a->id.'/edit')->assertOk()->assertSee('Preferred vendor');
        $this->get('/admin/vendors/create')->assertOk()->assertSee('Lead time');
        $this->get('/admin/reorder-suggestions')->assertOk();
    }
}
