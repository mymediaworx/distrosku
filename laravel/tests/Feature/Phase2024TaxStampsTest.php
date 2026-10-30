<?php

namespace Tests\Feature;

use App\Compliance\TaxStamps;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\TaxStampsPage;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** #40 part 2 (0.20.24): cigarette tax stamping, off by default. */
class Phase2024TaxStampsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $raw;

    private Product $ky;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->main = Warehouse::where('code', 'MAIN')->firstOrFail();
        $this->raw = Product::factory()->create(['sku' => 'CIG-UNST', 'tax_class' => 'cigarette_stamped', 'cost' => '6']);
        $this->ky = Product::factory()->create(['sku' => 'CIG-KY', 'tax_class' => 'cigarette_stamped', 'stamp_state' => 'KY', 'price' => '9']);
        app(InventoryService::class)->move($this->raw, $this->main, 100, 'receipt');
        Company::current()->update(['state' => 'KY']);
    }

    private function on(): void
    {
        Setting::put('stamping_enabled', '1');
    }

    private function qty(Product $p): float
    {
        return (float) Inventory::where('product_id', $p->id)->where('warehouse_id', $this->main->id)->value('qty');
    }

    public function test_off_by_default_hides_the_page_and_refuses_records(): void
    {
        $this->assertFalse(TaxStamps::enabled());
        $this->actingAs($this->admin);
        $this->assertFalse(TaxStampsPage::canAccess());
        $this->expectException(WorkflowException::class);
        app(TaxStamps::class)->buy(['state' => 'KY', 'pack_size' => 20, 'stamps' => 10], $this->admin);
    }

    public function test_buy_affix_moves_stock_and_reconciles(): void
    {
        $this->on();
        $svc = app(TaxStamps::class);
        $svc->buy(['state' => 'KY', 'pack_size' => 20, 'stamps' => 60, 'unit_cost' => '0.011', 'purchased_on' => now()->toDateString()], $this->admin);
        $svc->affix(['from_product_id' => $this->raw->id, 'to_product_id' => $this->ky->id, 'warehouse_id' => $this->main->id, 'pack_size' => 20, 'packs' => 40], $this->admin);

        $this->assertSame([60.0, 40.0], [$this->qty($this->raw), $this->qty($this->ky)]);
        $this->assertSame(20, TaxStamps::onHand('KY', 20));
        $r = TaxStamps::reconciliation(now()->startOfMonth()->toDateString(), now()->toDateString())[0];
        $this->assertSame(['state' => 'KY', 'pack_size' => 20, 'opening' => 0, 'bought' => 60, 'affixed' => 40, 'closing' => 20], $r);
    }

    public function test_affixing_needs_enough_stamps_and_the_right_skus(): void
    {
        $this->on();
        $svc = app(TaxStamps::class);
        $svc->buy(['state' => 'KY', 'pack_size' => 20, 'stamps' => 5], $this->admin);
        foreach ([
            ['from_product_id' => $this->raw->id, 'to_product_id' => $this->ky->id, 'warehouse_id' => $this->main->id, 'packs' => 6],   // not enough stamps
            ['from_product_id' => $this->ky->id, 'to_product_id' => $this->ky->id, 'warehouse_id' => $this->main->id, 'packs' => 1],    // stamped as source
            ['from_product_id' => $this->raw->id, 'to_product_id' => $this->raw->id, 'warehouse_id' => $this->main->id, 'packs' => 1],  // unstamped target
        ] as $bad) {
            try {
                $svc->affix($bad, $this->admin);
                $this->fail('accepted bad stamping');
            } catch (WorkflowException) {
            }
        }
        $this->assertSame(100.0, $this->qty($this->raw));
    }

    public function test_stamped_stock_cannot_be_delivered_to_another_state(): void
    {
        $this->on();
        $in = Customer::where('code', 'CTEST-IN')->firstOrFail();
        $so = SalesOrder::create(['customer_id' => $in->id, 'warehouse_id' => $this->main->id, 'order_date' => now(), 'fulfillment' => 'delivery',
            'ship_to_id' => $in->shipTos()->value('id')]);
        try {
            $so->lines()->create(['product_id' => $this->ky->id, 'qty' => 1, 'unit_price' => '9']);
            $this->fail('KY-stamped stock shipped to IN');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('KY tax stamp', $e->validator->errors()->first());
        }
        $ky = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $so2 = SalesOrder::create(['customer_id' => $ky->id, 'warehouse_id' => $this->main->id, 'order_date' => now(), 'fulfillment' => 'pickup']);
        $this->assertNotNull($so2->lines()->create(['product_id' => $this->ky->id, 'qty' => 1, 'unit_price' => '9'])->id);
    }

    public function test_page_records_through_header_actions(): void
    {
        $this->on();
        $this->actingAs($this->admin);
        Livewire::test(TaxStampsPage::class)
            ->callAction('buy', ['state' => 'KY', 'pack_size' => 20, 'stamps' => 30, 'unit_cost' => '0', 'purchased_on' => now()->toDateString()])
            ->callAction('affix', ['from_product_id' => $this->raw->id, 'to_product_id' => $this->ky->id, 'warehouse_id' => $this->main->id, 'pack_size' => 20, 'packs' => 10, 'affixed_on' => now()->toDateString()])
            ->assertSee('CIG-KY');
        $this->assertSame(20, TaxStamps::onHand('KY', 20));
        $this->assertDatabaseHas('activity_logs', ['event' => 'tax_stamps_affixed']);
    }
}
