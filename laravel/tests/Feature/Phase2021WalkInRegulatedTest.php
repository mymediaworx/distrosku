<?php

namespace Tests\Feature;

use App\Compliance\WalkInRule;
use App\Exceptions\ComplianceException;
use App\Filament\Forms\Components\ProductPickerField;
use App\Livewire\CounterPos;
use App\Livewire\Driver\VanSale;
use App\Livewire\ProductPicker;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Portal\PortalService;
use App\Services\ComplianceChecker;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.21: walk-in / cash customers cannot buy regulated products (tobacco, vapor, CBD/hemp, kratom). */
class Phase2021WalkInRegulatedTest extends TestCase
{
    use RefreshDatabase;

    private Customer $cash;

    private Customer $flagged;

    private Customer $acct;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        $this->cash = Customer::where('code', 'CASH')->firstOrFail();
        $this->flagged = Customer::create(['code' => 'DRIVEIN', 'name' => 'Drive-in Sales', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true, 'walk_in' => true, 'state' => 'KY']);
        $this->acct = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $this->main = Warehouse::where('code', 'MAIN')->firstOrFail();
    }

    private function p(string $sku): Product
    {
        return Product::where('sku', $sku)->firstOrFail();
    }

    // ---- the rule ---------------------------------------------------------------------------------

    public function test_rule_covers_cash_code_and_flagged_customers_only(): void
    {
        $this->assertTrue(WalkInRule::isWalkIn($this->cash));
        $this->assertTrue(WalkInRule::isWalkIn($this->flagged));
        $this->assertFalse(WalkInRule::isWalkIn($this->acct));
        $this->assertFalse(WalkInRule::isWalkIn(null));
        // code alone is enough, even when the flag column is false (migration not yet run)
        $this->cash->forceFill(['walk_in' => false])->save();
        $this->assertTrue(WalkInRule::isWalkIn($this->cash->fresh()));
    }

    public function test_every_regulated_class_is_blocked_and_general_and_beverage_are_not(): void
    {
        foreach (['TST-CIG-001', 'TST-CGR-001', 'TST-PIP-001', 'TST-SNF-001', 'TST-VAP-001', 'TST-HMP-001', 'TST-HMP-002', 'TST-KRT-001'] as $sku) {
            $this->assertTrue($this->p($sku)->isRegulated(), $sku);
            $this->assertTrue(WalkInRule::blocks($this->cash, $this->p($sku)), $sku);
            $this->assertTrue(WalkInRule::blocks($this->flagged, $this->p($sku)), $sku);
            $this->assertFalse(WalkInRule::blocks($this->acct, $this->p($sku)), $sku);
        }
        foreach (['TST-GEN-001', 'TST-BEV-001'] as $sku) {
            $this->assertFalse($this->p($sku)->isRegulated(), $sku);
            $this->assertFalse(WalkInRule::blocks($this->cash, $this->p($sku)), $sku);
        }
        $this->assertSame(8, Product::regulated()->whereIn('sku', ['TST-CIG-001', 'TST-CGR-001', 'TST-PIP-001', 'TST-SNF-001', 'TST-VAP-001', 'TST-HMP-001', 'TST-HMP-002', 'TST-KRT-001'])->count());
    }

    public function test_compliance_checker_reports_the_rule_for_walk_in_only(): void
    {
        $chk = app(ComplianceChecker::class);
        $ship = $this->acct->shipTos()->firstOrFail();
        $kratom = $this->p('TST-KRT-001');
        $this->assertContains(WalkInRule::message($kratom), $chk->checkLine($kratom, $this->cash, $ship, null, false));
        $this->assertContains(WalkInRule::message($kratom), $chk->checkLine($kratom, $this->flagged, $ship, null, false));
        $this->assertNotContains(WalkInRule::message($kratom), $chk->checkLine($kratom, $this->acct, $ship, null, false));
        $this->assertSame([], array_filter($chk->checkLine($this->p('TST-GEN-001'), $this->cash, $ship, null, false)));
    }

    // ---- POS ----------------------------------------------------------------------------------------

    public function test_pos_refuses_to_add_a_regulated_line_for_walk_in_and_cash_customers(): void
    {
        foreach ([$this->cash, $this->flagged] as $c) {
            Livewire::test(CounterPos::class)->set('customerId', $c->id)
                ->set('scan', 'TST-PIP-001')->call('add')
                ->assertSet('cart', [])->assertSet('error', WalkInRule::message($this->p('TST-PIP-001')));
        }
        $this->assertSame(0, SalesOrder::where('channel', 'pos')->count());
    }

    public function test_pos_browse_pick_is_refused_and_tiles_are_disabled(): void
    {
        $lw = Livewire::test(CounterPos::class)->set('customerId', $this->cash->id)->call('openBrowse');
        $lw->assertSeeHtml('data-restricted="1"')->assertSee('Not for walk-in');
        $lw->call('pick', $this->p('TST-KRT-001')->id)->assertSet('pickProductId', null)
            ->assertSet('error', WalkInRule::message($this->p('TST-KRT-001')));
        $tiles = $lw->get('browseTiles');
        $by = collect($tiles)->keyBy('sku');
        $this->assertTrue($by['TST-KRT-001']['restricted']);
        $this->assertFalse($by['TST-GEN-001']['restricted']);
        // a normal customer sees no restricted tiles
        $lw2 = Livewire::test(CounterPos::class)->set('customerId', $this->acct->id)->call('openBrowse');
        $lw2->assertDontSeeHtml('data-restricted="1"');
    }

    public function test_pos_switching_to_walk_in_with_regulated_in_cart_blocks_checkout(): void
    {
        $lw = Livewire::test(CounterPos::class)->set('customerId', $this->acct->id)->set('scan', 'TST-PIP-001')->call('add');
        $this->assertCount(1, $lw->get('cart'));
        $lw->set('customerId', $this->cash->id)->assertSet('blocked', true)
            ->assertSee("can't buy regulated products")->call('checkout');
        $this->assertSame(0, SalesOrder::where('channel', 'pos')->count());
    }

    public function test_pos_still_sells_non_regulated_products_to_cash(): void
    {
        Livewire::test(CounterPos::class)->set('customerId', $this->cash->id)
            ->set('scan', 'TST-GEN-001')->call('add')->assertSet('error', null)->call('checkout');
        $this->assertSame(1, SalesOrder::where('channel', 'pos')->count());
    }

    // ---- van sale -----------------------------------------------------------------------------------

    private function vanDriver(): User
    {
        $driver = User::where('role', 'driver')->firstOrFail();
        $van = Warehouse::create(['code' => 'VAN21', 'name' => 'Van 21', 'state' => 'KY', 'is_van' => true, 'van_driver_id' => $driver->id]);
        foreach (['TST-GEN-001', 'TST-PIP-001'] as $sku) {
            app(InventoryService::class)->move($this->p($sku), $van, '50', 'adjustment', null, $this->p($sku)->cost, 'load van');
        }

        return $driver;
    }

    public function test_van_sale_refuses_regulated_line_for_walk_in_and_drops_existing_ones_on_switch(): void
    {
        $driver = $this->vanDriver();
        Livewire::actingAs($driver)->test(VanSale::class)->set('customerId', $this->cash->id)
            ->set('scan', 'TST-PIP-001')->call('add')
            ->assertSet('cart', [])->assertSet('err', WalkInRule::message($this->p('TST-PIP-001')))
            ->set('scan', 'TST-GEN-001')->call('add')->assertCount('cart', 1);

        $lw = Livewire::actingAs($driver)->test(VanSale::class)->set('customerId', $this->acct->id)
            ->set('scan', 'TST-PIP-001')->call('add');
        $this->assertCount(1, $lw->get('cart'));
        $lw->set('customerId', $this->flagged->id)->assertSet('cart', [])->assertSee('Removed 1 regulated item');
    }

    // ---- sales orders -------------------------------------------------------------------------------

    private function so(Customer $c): SalesOrder
    {
        return SalesOrder::create(['number' => 'SO-W'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $c->resolveShipToId(), 'fulfillment' => 'pickup',
            'warehouse_id' => $this->main->id, 'order_date' => now()->toDateString()]);
    }

    public function test_sales_order_line_for_walk_in_customer_cannot_be_a_regulated_product(): void
    {
        $so = $this->so($this->cash);
        try {
            $so->lines()->create(['product_id' => $this->p('TST-VAP-001')->id, 'qty' => 1, 'unit_price' => '9.99']);
            $this->fail('regulated line was saved for a walk-in customer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("Walk-in / cash customers can't buy regulated products", $e->errors()['product_id'][0]);
        }
        $this->assertSame(0, $so->lines()->count());
        $so->lines()->create(['product_id' => $this->p('TST-GEN-001')->id, 'qty' => 1, 'unit_price' => '25']); // allowed
        $this->assertSame(1, $so->lines()->count());
    }

    public function test_changing_a_sales_order_to_a_walk_in_customer_is_refused_when_it_has_regulated_lines(): void
    {
        $so = $this->so($this->acct);
        $so->lines()->create(['product_id' => $this->p('TST-PIP-001')->id, 'qty' => 1, 'unit_price' => '13.5']);
        try {
            $so->update(['customer_id' => $this->cash->id]);
            $this->fail('customer switch allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }
        $this->assertSame($this->acct->id, $so->fresh()->customer_id);
    }

    public function test_confirm_is_blocked_for_a_legacy_walk_in_order_with_regulated_lines(): void
    {
        // simulate an order created before the rule existed (bypass the model hooks)
        $so = $this->so($this->cash);
        \DB::table('sales_order_lines')->insert(['sales_order_id' => $so->id, 'product_id' => $this->p('TST-PIP-001')->id, 'qty' => 1, 'unit_price' => '13.5', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(ComplianceException::class);
        app(SalesOrderService::class)->confirm($so->fresh());
    }

    public function test_sales_order_form_rejects_regulated_line_for_walk_in_and_accepts_it_for_an_account(): void
    {
        $pipe = $this->p('TST-PIP-001');
        $line = ['product_id' => $pipe->id, 'qty' => 1, 'unit_price' => '13.50'];
        Livewire::test(\App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder::class)
            ->fillForm(['customer_id' => $this->cash->id, 'warehouse_id' => $this->main->id, 'order_date' => now()->toDateString(), 'fulfillment' => 'pickup', 'lines' => [$line]])
            ->call('create')->assertHasFormErrors(['lines.0.product_id']);
        $this->assertSame(0, SalesOrder::where('customer_id', $this->cash->id)->count());

        // switching the customer to a walk-in while regulated lines exist fails on the customer field
        Livewire::test(\App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder::class)
            ->fillForm(['customer_id' => $this->flagged->id, 'warehouse_id' => $this->main->id, 'order_date' => now()->toDateString(), 'fulfillment' => 'pickup', 'lines' => [$line]])
            ->call('create')->assertHasFormErrors(['customer_id']);
    }

    public function test_product_picker_action_filters_regulated_server_side(): void
    {
        $this->assertTrue((new \ReflectionMethod(ProductPickerField::class, 'addLinesAction'))->getNumberOfParameters() >= 5);
        $this->assertTrue(ProductPickerField::make('x')->hideRegulatedWhen()->getRestrictRegulated());
        $this->assertFalse(ProductPickerField::make('x')->getRestrictRegulated());
    }

    // ---- picker ---------------------------------------------------------------------------------------

    public function test_picker_hides_regulated_products_and_refuses_them_when_restricted(): void
    {
        $pipe = $this->p('TST-PIP-001');
        $gen = $this->p('TST-GEN-001');
        Livewire::test(ProductPicker::class, ['multi' => true, 'restrictRegulated' => true])
            ->set('search', 'TEST')->assertSee($gen->sku)->assertDontSee($pipe->sku)
            ->call('toggle', $pipe->id)->assertSet('value', [])->assertSee("can't buy regulated products")
            ->call('toggle', $gen->id)->assertSet('value', [$gen->id])
            ->call('openBrowse')->assertDontSee('wire:key="tile-'.$pipe->id.'"', false);
        Livewire::test(ProductPicker::class, ['multi' => true])->set('search', 'TEST')->assertSee($pipe->sku);
    }

    // ---- portal ---------------------------------------------------------------------------------------

    public function test_portal_blocks_regulated_for_a_walk_in_flagged_customer_but_not_general(): void
    {
        $k = CustomerContact::create(['customer_id' => $this->flagged->id, 'name' => 'Pat', 'email' => 'pat21@example.test', 'is_portal_contact' => true]);
        $ship = $this->flagged->shipTos()->first() ?? $this->flagged->shipTos()->create(['name' => 'Main', 'address1' => '1 Main St', 'city' => 'Louisville', 'state' => 'KY', 'zip' => '40202']);
        $svc = app(PortalService::class);
        $this->assertContains(WalkInRule::message($this->p('TST-HMP-002')), $svc->reasons($this->p('TST-HMP-002'), $k->fresh(), $ship));
        $this->assertNotContains(WalkInRule::message($this->p('TST-GEN-001')), $svc->reasons($this->p('TST-GEN-001'), $k->fresh(), $ship));
        try {
            $svc->submit($k->fresh(), $ship, [$this->p('TST-HMP-002')->id => '1']);
            $this->fail('portal order for regulated product was accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString("can't buy regulated products", $e->getMessage().json_encode(method_exists($e, 'errors') ? $e->errors() : []).json_encode(method_exists($e, 'reasons') ? $e->reasons() : []));
        }
        $this->assertSame(0, SalesOrder::where('customer_id', $this->flagged->id)->count());
    }

    // ---- data: migration, toggle, report ----------------------------------------------------------------

    public function test_migration_flags_the_cash_customer_and_is_rerunnable(): void
    {
        Customer::where('code', 'CASH')->update(['walk_in' => false]);
        $m = require database_path('migrations/2026_11_30_000001_phase2021_walk_in_customers.php');
        $m->up();
        $m->up();
        $this->assertTrue((bool) Customer::where('code', 'CASH')->value('walk_in'));
        $this->assertFalse((bool) $this->acct->fresh()->walk_in);
    }

    public function test_customer_form_has_a_walk_in_toggle(): void
    {
        $page = Livewire::test(\App\Filament\Resources\CustomerResource\Pages\EditCustomer::class, ['record' => $this->acct->getKey()]);
        $page->assertFormFieldExists('walk_in')->fillForm(['walk_in' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue((bool) $this->acct->fresh()->walk_in);
        $this->assertTrue(WalkInRule::isWalkIn($this->acct->fresh()));
    }

    public function test_regulated_report_command_counts_products_and_lists_misses(): void
    {
        Product::where('sku', 'TST-GEN-001')->update(['category' => 'Vape accessories']);
        $this->artisan('erp:regulated-report')
            ->expectsOutputToContain('Regulated products (blocked for walk-in / cash): ')
            ->expectsOutputToContain('CASH')
            ->expectsOutputToContain('TST-GEN-001')
            ->assertSuccessful();
    }
}
