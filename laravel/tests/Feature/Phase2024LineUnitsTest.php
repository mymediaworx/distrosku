<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrderResource\Pages\CreatePurchaseOrder;
use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/** #25 (0.20.24): sales and purchase order lines can be entered by case / carton; stock, price and cost stay per stock unit. */
class Phase2024LineUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Product $p;

    private ProductUnit $ctn;

    private ProductUnit $cs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->p = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $this->p->update(['base_unit_name' => 'Pack']);
        $this->ctn = $this->p->units()->create(['code' => 'CTN', 'name' => 'Carton', 'factor' => 10]);
        $this->cs = $this->p->units()->create(['code' => 'CS', 'name' => 'Case', 'factor' => 50]);
    }

    public function test_migration_adds_unit_columns_to_both_line_tables(): void
    {
        foreach (['sales_order_lines', 'purchase_order_lines'] as $t) {
            $this->assertTrue(Schema::hasColumns($t, ['product_unit_id', 'unit_qty']), $t);
        }
        $m = require database_path('migrations/2026_12_02_000001_phase2024_line_units.php');
        $m->up(); // re-runnable
        $this->assertTrue(Schema::hasColumn('sales_order_lines', 'unit_qty'));
    }

    public function test_model_sets_stock_qty_from_the_unit_and_drops_a_unit_from_another_product(): void
    {
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $so = SalesOrder::create(['customer_id' => $c->id, 'warehouse_id' => \App\Models\Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()]);

        $line = $so->lines()->create(['product_id' => $this->p->id, 'product_unit_id' => $this->ctn->id, 'unit_qty' => 3, 'qty' => 1, 'unit_price' => $this->p->price]);
        $this->assertEqualsWithDelta(30, (float) $line->fresh()->qty, 0.0001);   // browser said 1; server makes it 3 x 10
        $this->assertSame('3 CTN', $line->fresh()->unitLabel());

        $other = Product::where('sku', '!=', $this->p->sku)->where('active', true)->notRegulated()->firstOrFail();
        $foreign = $other->units()->create(['code' => 'CS', 'name' => 'Case', 'factor' => 24]);
        $l2 = $so->lines()->create(['product_id' => $this->p->id, 'product_unit_id' => $foreign->id, 'unit_qty' => 2, 'qty' => 5, 'unit_price' => $this->p->price]);
        $this->assertNull($l2->fresh()->product_unit_id);
        $this->assertEqualsWithDelta(5, (float) $l2->fresh()->qty, 0.0001);

        $line->update(['product_unit_id' => null, 'unit_qty' => 7, 'qty' => 4]);       // back to the stock unit
        $this->assertNull($line->fresh()->unit_qty);
        $this->assertEqualsWithDelta(4, (float) $line->fresh()->qty, 0.0001);
    }

    public function test_sales_order_form_orders_by_carton_and_prices_per_pack(): void
    {
        $undo = Repeater::fake();
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $lw = Livewire::test(CreateSalesOrder::class)
            ->set('data.customer_id', $c->id)->set('data.ship_to_id', $c->shipTos()->value('id'))
            ->set('data.lines', [['product_id' => null, 'qty' => 1, 'unit_price' => null]])
            ->set('data.lines.0.product_id', $this->p->id)
            ->set('data.lines.0.product_unit_id', $this->ctn->id);
        $this->assertSame('10', (string) $lw->get('data.lines.0.qty'));
        $lw->set('data.lines.0.unit_qty', 2);
        $this->assertSame('20', (string) $lw->get('data.lines.0.qty'));
        $lw->call('create')->assertHasNoFormErrors();
        $undo();

        $line = SalesOrder::latest('id')->first()->lines()->first();
        $this->assertSame([$this->ctn->id, 20.0, 2.0], [$line->product_unit_id, (float) $line->qty, (float) $line->unit_qty]);
        $this->assertEqualsWithDelta((float) $this->p->price, (float) $line->unit_price, 0.0001);   // price stays per pack
        $this->assertEqualsWithDelta(20 * (float) $this->p->price, (float) $line->line_total, 0.01);
    }

    public function test_changing_the_product_resets_the_unit(): void
    {
        $other = Product::where('sku', '!=', $this->p->sku)->where('active', true)->notRegulated()->firstOrFail();
        $lw = Livewire::test(CreateSalesOrder::class)
            ->set('data.lines', [['product_id' => null, 'qty' => 1, 'unit_price' => null]])
            ->set('data.lines.0.product_id', $this->p->id)
            ->set('data.lines.0.product_unit_id', $this->cs->id)
            ->set('data.lines.0.product_id', $other->id);
        $this->assertNull($lw->get('data.lines.0.product_unit_id'));
        $this->assertNull($lw->get('data.lines.0.unit_qty'));
    }

    public function test_purchase_order_form_buys_by_case_with_cost_per_pack(): void
    {
        $undo = Repeater::fake();
        $v = Vendor::factory()->create();
        Livewire::test(CreatePurchaseOrder::class)
            ->set('data.vendor_id', $v->id)->set('data.warehouse_id', \App\Models\Warehouse::where('code', 'MAIN')->value('id'))
            ->set('data.lines', [['product_id' => null, 'qty' => 1, 'unit_cost' => null]])
            ->set('data.lines.0.product_id', $this->p->id)
            ->set('data.lines.0.product_unit_id', $this->cs->id)
            ->set('data.lines.0.unit_qty', 4)
            ->set('data.lines.0.unit_cost', '1.25')
            ->call('create')->assertHasNoFormErrors();
        $undo();

        $line = PurchaseOrder::latest('id')->first()->lines()->first();
        $this->assertSame([$this->cs->id, 200.0, 4.0, 1.25], [$line->product_unit_id, (float) $line->qty, (float) $line->unit_qty, (float) $line->unit_cost]);
        $this->assertSame('4 CS', $line->unitLabel());
    }

    public function test_invoice_shows_the_unit_the_line_was_ordered_in(): void
    {
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $so = SalesOrder::create(['customer_id' => $c->id, 'warehouse_id' => \App\Models\Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()]);
        $so->lines()->create(['product_id' => $this->p->id, 'product_unit_id' => $this->ctn->id, 'unit_qty' => 3, 'qty' => 30, 'unit_price' => $this->p->price]);

        $html = view('docs.invoice', ['so' => $so->fresh('lines.product', 'lines.unit'), 'company' => \App\Models\Company::current(), 'info' => config('erp.company'), 'screen' => true])->render();
        $this->assertStringContainsString('3 CTN', $html);
    }
}
