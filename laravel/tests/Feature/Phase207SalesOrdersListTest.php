<?php

namespace Tests\Feature;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrderResource;
use App\Filament\Resources\SalesOrderResource\Pages\ListSalesOrders;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #4: Sales Orders list column cleanup (display only). */
class Phase207SalesOrdersListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
    }

    private function order(string $fulfillment, string $status = 'draft'): SalesOrder
    {
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();

        return SalesOrder::create(['number' => 'SO-L'.uniqid(), 'customer_id' => $c->id, 'fulfillment' => $fulfillment,
            'ship_to_id' => $fulfillment === 'delivery' ? $c->shipTos()->value('id') : null, 'status' => $status,
            'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
    }

    public function test_st_column_is_gone_and_phone_summary_column_exists(): void
    {
        Livewire::test(ListSalesOrders::class)
            ->assertTableColumnDoesNotExist('shipTo.state')
            ->assertTableColumnExists('order_summary')
            ->assertTableColumnExists('progress')
            ->assertTableColumnExists('status');
    }

    public function test_customer_description_shows_fulfillment_and_ship_to_state(): void
    {
        $pickup = $this->order('pickup');
        $delivery = $this->order('delivery');

        $this->assertSame('Pickup', SalesOrderResource::fulfillmentLabel($pickup));
        $this->assertSame('Delivery · KY', SalesOrderResource::fulfillmentLabel($delivery->load('shipTo')));

        Livewire::test(ListSalesOrders::class)
            ->assertCanSeeTableRecords([$pickup, $delivery])
            ->assertSee('Delivery · KY');
    }

    public function test_single_status_column_names_draft_cancelled_and_picked(): void
    {
        $this->assertStringContainsString('>Draft<', SalesOrderResource::progressHtml($this->order('pickup', 'draft')));
        $this->assertStringContainsString('>Cancelled<', SalesOrderResource::progressHtml($this->order('pickup', 'cancelled')));

        $picked = $this->order('pickup', 'picked');
        $html = SalesOrderResource::progressHtml($picked);
        $this->assertStringContainsString('>Picked<', $html);
        $this->assertStringNotContainsString('>Confirmed<', $html);

        $confirmed = $this->order('pickup', 'confirmed');
        $this->assertSame($confirmed->stageChips(), SalesOrderResource::progressHtml($confirmed));
        $this->assertSame(SalesOrderStatus::Confirmed, $confirmed->status);
    }

    public function test_long_customer_names_are_truncated_not_wrapped(): void
    {
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $c->update(['name' => 'TEST A Very Long Convenience Store Name That Wraps']);
        $this->order('pickup');

        Livewire::test(ListSalesOrders::class)
            ->assertSee('TEST A Very Long Convenience Sto...')
            ->assertSee('TEST A Very Long...');   // phone summary column
    }

    public function test_search_still_finds_orders_by_number_and_customer(): void
    {
        $a = $this->order('pickup');
        $other = SalesOrder::create(['number' => 'SO-ZZOTHER', 'customer_id' => Customer::where('code', 'CTEST-IN')->value('id'),
            'fulfillment' => 'pickup', 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);

        Livewire::test(ListSalesOrders::class)->searchTable($a->number)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$other]);
        Livewire::test(ListSalesOrders::class)->searchTable('Wholesale (IN)')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$a]);
    }
}
