<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrderResource;
use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrderResource\Pages\EditSalesOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #5: line totals + order totals on the sales order screen (display only). */
class Phase207OrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
    }

    private function confirmed(): SalesOrder
    {
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $so = SalesOrder::create(['number' => 'SO-T'.uniqid(), 'customer_id' => $c->id, 'fulfillment' => 'pickup',
            'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'qty' => '2', 'unit_price' => '25.0000']);
        $so->lines()->create(['product_id' => Product::where('sku', 'TST-CGR-001')->value('id'), 'qty' => '4', 'unit_price' => '0.9900']);

        return app(SalesOrderService::class)->confirm($so)->fresh();
    }

    public function test_display_helpers_trim_zeros_without_losing_precision(): void
    {
        $this->assertSame('4', SalesOrderResource::displayQty('4.0000'));
        $this->assertSame('2.5', SalesOrderResource::displayQty('2.5000'));
        $this->assertSame('10', SalesOrderResource::displayQty('10'));
        $this->assertSame('0.99', SalesOrderResource::displayPrice('0.9900'));
        $this->assertSame('0.655', SalesOrderResource::displayPrice('0.6550'));
        $this->assertSame('24.00', SalesOrderResource::displayPrice('24.0000'));
        $this->assertNull(SalesOrderResource::displayPrice(null));
    }

    public function test_line_and_form_totals_are_exact_and_tolerate_partial_input(): void
    {
        $this->assertSame('3.9600', SalesOrderResource::lineTotal('4', '0.99'));
        $this->assertSame('0.0000', SalesOrderResource::lineTotal('', '0.99'));
        $this->assertSame('0.0000', SalesOrderResource::lineTotal(' 2x', '1'));
        $this->assertSame('53.9600', SalesOrderResource::formTotal([['qty' => '2', 'unit_price' => '25'], ['qty' => '4', 'unit_price' => '0.99']]));
        $this->assertSame('0.0000', SalesOrderResource::formTotal(null));
    }

    public function test_edit_screen_shows_line_totals_order_total_and_balance(): void
    {
        $so = $this->confirmed();
        $this->assertSame('53.9600', $so->total);

        Livewire::test(EditSalesOrder::class, ['record' => $so->id])
            ->assertSee('Line total')->assertSee('$50.00')->assertSee('$3.96')
            ->assertSeeHtml('data-test="order-total">$53.96</strong>')
            ->assertSeeHtml('data-test="balance-due">$53.96</strong>')
            ->assertSet('data.lines', fn ($lines) => collect($lines)->pluck('qty')->sort()->values()->all() === ['2', '4']);
    }

    public function test_balance_reflects_payments(): void
    {
        $so = $this->confirmed();
        app(SalesOrderService::class)->deliver($so);
        app(SalesOrderService::class)->recordPayment($so->fresh(), '20', 'cash');

        Livewire::test(EditSalesOrder::class, ['record' => $so->id])
            ->assertSeeHtml('data-test="balance-due">$33.96</strong>');
    }

    public function test_create_screen_total_updates_from_unsaved_lines_and_saves_exact_values(): void
    {
        $p = Product::where('sku', 'TST-PIP-001')->firstOrFail(); // 13.5000
        $c = Customer::where('code', 'CTEST-KY')->firstOrFail();

        $lw = Livewire::test(CreateSalesOrder::class)
            ->set('data.customer_id', $c->id)
            ->set('data.lines', [['product_id' => null, 'qty' => 1, 'unit_price' => null]])
            ->set('data.lines.0.product_id', $p->id)
            ->assertSet('data.lines.0.unit_price', '13.50')
            ->set('data.lines.0.qty', 3)
            ->assertSeeHtml('data-test="order-total">$40.50</strong>');

        $lw->call('create')->assertHasNoFormErrors();
        $line = SalesOrder::latest('id')->first()->lines()->first();
        $this->assertSame('3.0000', $line->qty);
        $this->assertSame('13.5000', $line->unit_price);
    }

    public function test_deliver_modals_state_the_amount(): void
    {
        $so = $this->confirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $so->id])
            ->mountAction('deliverCollect')
            ->assertSee('Order total $53.96, balance due $53.96.');
    }
}
