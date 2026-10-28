<?php

namespace Tests\Feature;

use App\Catalog\CatalogService;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\CounterPickupBoard;
use App\Filament\Pages\Settings;
use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Inventory\NegativeStock;
use App\Inventory\QuickCountService;
use App\Livewire\CounterPos;
use App\Livewire\CustomerPicker;
use App\Livewire\Driver\VanSale;
use App\Livewire\QuickTools;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Product;
use App\Models\QuickCount;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\InvoiceTax;
use App\Services\SalesOrderService;
use App\Support\CustomerSearch;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.20: Michael's 2026-10-28 walkthrough, items 1, 5, 8, 9 (part), 10, 11, 12. */
class Phase2020WalkthroughTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
        $this->main = Warehouse::where('code', 'MAIN')->first();
        $this->actingAs($this->owner);
    }

    private function cust(string $code, string $name, array $extra = []): Customer
    {
        return Customer::create(array_merge(['code' => $code, 'name' => $name, 'type' => 'retailer', 'terms' => 'net30', 'credit_limit' => 0, 'active' => true], $extra));
    }

    // ---- 1: customer picker -------------------------------------------------------------------

    public function test_customer_search_matches_account_name_dba_phone_and_city_in_any_order(): void
    {
        $a = $this->cust('ACME-7', 'Acme Wholesale LLC', ['dba' => 'Corner Smoke Shop', 'phone' => '(502) 555-0142']);
        $a->shipTos()->create(['name' => 'Main', 'city' => 'Louisville', 'state' => 'KY']);
        $b = $this->cust('BETA-1', 'Beta Foods', ['phone' => '606-555-0199']);
        $b->shipTos()->create(['name' => 'Main', 'city' => 'Lexington', 'state' => 'KY']);

        $ids = fn (string $q) => collect(CustomerSearch::results($q))->pluck('id')->all();
        $this->assertSame([$a->id], $ids('acme-7'));            // account number
        $this->assertSame([$a->id], $ids('wholesale acme'));    // name, any order
        $this->assertSame([$a->id], $ids('corner smoke'));      // DBA
        $this->assertSame([$a->id], $ids('5025550142'));        // phone, punctuation ignored
        $this->assertSame([$a->id], $ids('louisville'));        // city (from ship-to)
        $this->assertSame([$a->id], $ids('smoke louisville acme')); // mixed fields, any order
        $this->assertSame([$b->id], $ids('lexington beta'));
        $this->assertSame([], $ids('acme lexington'));          // every word must match
    }

    public function test_customer_picker_starts_blank_and_selects_a_search_result(): void
    {
        $a = $this->cust('PICK-1', 'Pick Me Market');
        Livewire::test(CustomerPicker::class)
            ->assertSet('value', null)
            ->assertSee('Required')
            ->set('search', 'pick me')
            ->assertSeeHtml('data-test="customer-result"')
            ->call('select', $a->id)
            ->assertSet('value', $a->id)
            ->assertSeeHtml('data-test="customer-selected"')
            ->call('clear')
            ->assertSet('value', null);
    }

    public function test_new_sales_order_has_no_default_customer_and_requires_one(): void
    {
        Livewire::test(CreateSalesOrder::class)
            ->assertFormSet(['customer_id' => null])
            ->call('create')
            ->assertHasFormErrors(['customer_id' => 'required']);
    }

    public function test_pos_has_no_default_customer_and_refuses_checkout_without_one(): void
    {
        $lw = Livewire::test(CounterPos::class)->assertSet('customerId', null)
            ->set('scan', 'TST-GEN-001')->call('add')->call('checkout');
        $lw->assertSet('error', 'Pick a customer first (search above, or tap Walk-in).');
        $this->assertSame(0, SalesOrder::where('channel', 'pos')->count());

        $cash = Customer::where('code', 'CASH')->value('id');
        Livewire::test(CounterPos::class)->assertSee('Walk-in')
            ->set('customerId', $cash)->set('scan', 'TST-GEN-001')->call('add')->call('checkout');
        $this->assertSame(1, SalesOrder::where('channel', 'pos')->count());
    }

    public function test_van_sale_has_no_default_customer(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'driver', 'active' => true]));
        Livewire::test(VanSale::class)->assertSet('customerId', null);
    }

    // ---- 5 / 10: TV mode, Marketing label -----------------------------------------------------

    public function test_large_type_tv_mode_is_gone_but_open_tv_remains(): void
    {
        $page = Livewire::test(CounterPickupBoard::class);
        $page->assertSeeHtml('data-test="tv-popout"')->assertSee('Open TV')->assertDontSee('TV mode');
        $this->assertFalse(method_exists(CounterPickupBoard::class, 'toggleTv'));
        $this->assertFalse(property_exists(CounterPickupBoard::class, 'tv'));
    }

    public function test_marketing_is_named_marketing_not_brandworx_marketing(): void
    {
        $this->assertSame('Marketing', \App\Filament\Pages\MarketingSettingsPage::getNavigationLabel());
        $this->assertStringNotContainsString('BrandWorx Marketing', file_get_contents(app_path('Filament/Resources/CustomerResource.php')));
    }

    // ---- 8: wider sales order lines ---------------------------------------------------------------

    public function test_sales_order_line_repeater_gives_the_product_half_the_row(): void
    {
        $src = file_get_contents(app_path('Filament/Resources/SalesOrderResource.php'));
        $this->assertStringContainsString("columns(['default' => 3, 'lg' => 12])", $src);
        $this->assertStringContainsString("'lg' => 6", $src);
        $this->assertStringContainsString("'data-test' => 'line-product'", $src);
    }

    // ---- 11: taxes on invoices ------------------------------------------------------------------------

    private function taxedInvoice(): SalesOrder
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $p->update(['taxes' => [['name' => 'Excise', 'basis' => 'per_unit', 'amount' => '0.50'], ['name' => 'City tax', 'basis' => 'percent', 'amount' => '5']]]);
        $cust = $this->cust('TAX-1', 'Tax Test Co');
        $so = SalesOrder::create(['customer_id' => $cust->id, 'warehouse_id' => $this->main->id, 'order_date' => now()->toDateString(), 'fulfillment' => 'pickup']);
        $so->lines()->create(['product_id' => $p->id, 'qty' => 3, 'unit_price' => '10']);
        $svc = app(SalesOrderService::class);
        $so = $svc->confirm($svc->recalc($so->fresh())->fresh())->fresh();

        return $svc->deliver($so)->fresh(['lines.product', 'customer']);
    }

    public function test_invoice_shows_unit_cost_tax_per_unit_and_line_total_with_tax(): void
    {
        $so = $this->taxedInvoice();
        $l = $so->lines->first();
        $this->assertSame(S::Invoiced, $so->status);
        $this->assertSame('1.0000', $l->tax_per_unit);                // 0.50 + 5% of 10.00
        $this->assertSame('3.0000', $l->tax_total);                   // x 3
        $this->assertSame('33.0000', InvoiceTax::lineWithTax($l));    // (10 + 1) x 3
        $this->assertSame('30.0000', $so->subtotal);
        $this->assertSame('3.0000', $so->tax_total);
        $this->assertSame('33.0000', $so->total);
        $s = InvoiceTax::summary($so);
        $this->assertSame(['Excise' => '1.5000', 'City tax' => '1.5000'], $s['by_name']);
    }

    public function test_invoice_screen_and_pdf_list_every_tax(): void
    {
        $so = $this->taxedInvoice();
        $html = $this->get(route('docs.invoice.view', $so))->assertOk()
            ->assertSee('Unit cost')->assertSee('Tax / unit')->assertSee('Line total')
            ->assertSee('Excise')->assertSee('City tax')->assertSee('Total tax')
            ->assertSee('33.00')->assertSee('1.00')->getContent();
        $this->assertStringContainsString('data-test="invoice-screen"', $html);
        $this->get(route('docs.invoice', $so))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(2, substr_count($html, 'data-test="tax-row"'));
    }

    public function test_tax_is_posted_to_sales_tax_payable_not_revenue(): void
    {
        $so = $this->taxedInvoice();
        $j = Journal::where('memo', "Invoice {$so->number}")->firstOrFail();
        $byCode = $j->lines()->with('account')->get()->groupBy(fn ($l) => $l->account->code);
        $this->assertEquals(33.0, (float) $byCode['1100']->sum('debit'));
        $this->assertEquals(3.0, (float) $byCode['2200']->sum('credit'));
        $this->assertEquals(30.0, (float) $byCode['4000']->sum('credit'));
    }

    public function test_untaxed_products_invoice_exactly_as_before(): void
    {
        $cust = $this->cust('NOTAX', 'No Tax Co');
        $so = SalesOrder::create(['customer_id' => $cust->id, 'warehouse_id' => $this->main->id, 'order_date' => now()->toDateString(), 'fulfillment' => 'pickup']);
        $so->lines()->create(['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'qty' => 2, 'unit_price' => '10']);
        $svc = app(SalesOrderService::class);
        $so = $svc->deliver($svc->confirm($svc->recalc($so->fresh())->fresh())->fresh())->fresh();
        $this->assertSame('20.0000', $so->total);
        $this->assertEquals(0.0, (float) $so->tax_total);
    }

    public function test_pos_total_includes_product_taxes(): void
    {
        Product::where('sku', 'TST-GEN-001')->update(['taxes' => [['name' => 'Excise', 'basis' => 'per_unit', 'amount' => '1']]]);
        Livewire::test(CounterPos::class)->set('customerId', Customer::where('code', 'CASH')->value('id'))
            ->set('scan', 'TST-GEN-001')->call('add')
            ->assertSeeHtml('data-test="pos-tax"')->assertSee('Includes $1.00 tax')->assertSee('26.00');
    }

    // ---- 12: duplicate a product -------------------------------------------------------------------------

    public function test_duplicate_product_gets_a_new_sku_and_blank_upc(): void
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $p->update(['upc' => '012345678905', 'brand' => 'Zed']);
        $copy = app(CatalogService::class)->duplicate($p->fresh());
        $this->assertNotSame($p->sku, $copy->sku);
        $this->assertNotSame($p->id, $copy->id);
        $this->assertNull($copy->upc);
        $this->assertSame('Zed', $copy->brand);
        $this->assertStringEndsWith('(copy)', $copy->name);
        $this->assertSame((string) $p->price, (string) $copy->price);
        $this->assertNull($copy->archived_at);
        $this->assertSame('012345678905', $p->fresh()->upc);   // the original is untouched
        $this->assertSame(0, Inventory::where('product_id', $copy->id)->count()); // no stock copied
        $again = app(CatalogService::class)->duplicate($p->fresh());
        $this->assertNotSame($copy->sku, $again->sku);
    }

    public function test_duplicate_action_opens_the_copy_for_edit(): void
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $p->update(['upc' => '012345678905']);
        $n = Product::count();
        Livewire::test(\App\Filament\Resources\ProductResource\Pages\EditProduct::class, ['record' => $p->getRouteKey()])
            ->callAction('duplicate')->assertHasNoActionErrors();
        $this->assertSame($n + 1, Product::count());
        $copy = Product::latest('id')->first();
        $this->assertNull($copy->upc);
    }

    // ---- 9: negative inventory setting ----------------------------------------------------------------------

    public function test_negative_inventory_is_off_by_default_and_refuses_overselling(): void
    {
        $this->assertFalse(NegativeStock::allowed());
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $this->expectException(\Throwable::class);
        app(InventoryService::class)->move($p, $this->main, '-100000', 'sale');
    }

    public function test_negative_inventory_setting_allows_a_sale_to_go_below_zero_but_nothing_else(): void
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $inv = app(InventoryService::class);
        NegativeStock::set(true);
        $this->assertTrue(NegativeStock::allowed());
        $inv->move($p, $this->main, '-100000', 'sale');
        $this->assertTrue((float) Inventory::where('product_id', $p->id)->where('warehouse_id', $this->main->id)->value('qty') < 0);
        // adjustments / transfers still may not go negative
        $this->expectException(\Throwable::class);
        $inv->move($p, $this->main, '-100000', 'adjustment');
    }

    public function test_negative_inventory_setting_is_on_the_settings_page_and_saves(): void
    {
        Livewire::test(Settings::class)->assertFormSet(['allow_negative_inventory_sales' => false])
            ->fillForm(['allow_negative_inventory_sales' => true])->call('save');
        $this->assertTrue(NegativeStock::allowed());
        $this->assertSame(1, \App\Models\ActivityLog::where('event', 'negative_inventory_sales')->count());
    }

    // ---- 9: quick counts -------------------------------------------------------------------------------------------

    public function test_quick_tools_count_tab_records_a_count_without_moving_stock(): void
    {
        $token = Company::current()->rotateToken();
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $before = (string) Inventory::where('product_id', $p->id)->where('warehouse_id', $this->main->id)->value('qty');
        Livewire::test(QuickTools::class, ['token' => $token])->set('tab', 'count')
            ->set('cq', 'TST-GEN-001')->call('countSearch')
            ->set('count_warehouse_id', $this->main->id)->set('count_qty', '12')->set('count_by', 'Sam')->set('count_note', 'back shelf')
            ->call('submitCount')->assertHasNoErrors()->assertSeeHtml('data-test="count-done"');
        $q = QuickCount::firstOrFail();
        $this->assertSame('pending', $q->status);
        $this->assertSame('Sam', $q->counted_by);
        $this->assertSame($before, (string) Inventory::where('product_id', $p->id)->where('warehouse_id', $this->main->id)->value('qty'));
    }

    public function test_quick_count_needs_a_name_and_quantity(): void
    {
        $token = Company::current()->rotateToken();
        Livewire::test(QuickTools::class, ['token' => $token])->set('tab', 'count')
            ->set('cq', 'TST-GEN-001')->call('countSearch')->set('count_warehouse_id', $this->main->id)
            ->call('submitCount')->assertHasErrors(['count_qty', 'count_by']);
    }

    public function test_admin_applies_a_quick_count_as_an_adjustment_or_dismisses_it(): void
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $svc = app(QuickCountService::class);
        $q = $svc->submit($p->id, $this->main->id, '7', 'Sam');
        $svc->apply($q, $this->owner);
        $this->assertEquals(7.0, (float) Inventory::where('product_id', $p->id)->where('warehouse_id', $this->main->id)->value('qty'));
        $this->assertSame('applied', $q->fresh()->status);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'type' => 'adjustment', 'note' => 'Quick count by Sam']);
        try {
            $svc->apply($q->fresh(), $this->owner);
            $this->fail('A count can only be applied once.');
        } catch (WorkflowException) {
            $this->assertTrue(true);
        }
        $q2 = $svc->submit($p->id, $this->main->id, '1', 'Pat');
        $svc->dismiss($q2, $this->owner);
        $this->assertSame('dismissed', $q2->fresh()->status);
        $this->assertEquals(7.0, (float) Inventory::where('product_id', $p->id)->where('warehouse_id', $this->main->id)->value('qty'));
        $this->expectException(WorkflowException::class);
        $svc->submit($p->id, $this->main->id, '-1', 'Pat');
    }
}
