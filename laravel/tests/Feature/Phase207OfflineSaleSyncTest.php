<?php

namespace Tests\Feature;

use App\Filament\Resources\OfflineSaleResource\Pages\ListOfflineSales;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Pos\OfflineSaleSync;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #26 (server side): offline sales arrive, go through the same checkout as live sales, and are never dropped. */
class Phase207OfflineSaleSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $gen;

    private int $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->cashier = User::factory()->create(['role' => 'sales']);
        $this->gen = Product::where('sku', 'TST-GEN-001')->firstOrFail(); // 25.0000 list, not regulated
        $this->main = (int) Warehouse::where('code', 'MAIN')->value('id');
    }

    private function sale(array $over = []): array
    {
        return array_replace([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->cashier->id,
            'sold_at' => now()->subHours(2)->toIso8601String(), 'catalog_at' => now()->subHours(5)->toIso8601String(),
            'customer_id' => Customer::where('code', 'CASH')->value('id'), 'warehouse_id' => $this->main, 'fulfillment' => 'pickup',
            'tender' => 'cash', 'total' => '50.00', 'lines' => [['product_id' => $this->gen->id, 'qty' => '2', 'price' => '25.00']],
        ], $over);
    }

    private function stock(): float
    {
        return (float) Inventory::where('product_id', $this->gen->id)->where('warehouse_id', $this->main)->value('qty');
    }

    public function test_cash_sale_completes_like_a_live_sale_and_a_resend_changes_nothing(): void
    {
        $before = $this->stock();
        $sale = $this->sale(['sold_at' => '2026-10-20T15:30:00-04:00', 'catalog_at' => '2026-10-20T08:00:00-04:00']);

        $row = app(OfflineSaleSync::class)->ingest($sale, $this->cashier, 'tablet-1');
        $this->assertSame(OfflineSale::COMPLETED, $row->status);
        $so = $row->salesOrder;
        $this->assertSame('invoiced', $so->status->value);
        $this->assertSame('pos', $so->channel);
        $this->assertSame('2026-10-20', $so->order_date->toDateString());   // the day it was sold, not the day it synced
        $this->assertStringStartsWith('Offline sale '.substr($sale['uuid'], 0, 8), $so->notes);
        $this->assertSame('50.0000', $so->amount_paid);
        $this->assertSame($before - 2, $this->stock());
        $this->assertSame($this->cashier->id, $row->user_id);
        $this->assertTrue(ActivityLog::where('event', 'offline_sale_synced')->exists());

        $again = app(OfflineSaleSync::class)->ingest($sale, $this->cashier, 'tablet-1');
        $this->assertSame($row->id, $again->id);
        $this->assertSame(1, SalesOrder::where('channel', 'pos')->count());
        $this->assertSame($before - 2, $this->stock());
    }

    public function test_card_tender_and_stale_prices_go_to_review(): void
    {
        $card = app(OfflineSaleSync::class)->ingest($this->sale(['tender' => 'card']), $this->cashier);
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $card->status);
        $this->assertStringContainsString("can't be taken offline", $card->problems[0]);

        $stale = app(OfflineSaleSync::class)->ingest($this->sale(['catalog_at' => now()->subHours(60)->toIso8601String()]), $this->cashier);
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $stale->status);
        $this->assertStringContainsString('older than 48 hours', $stale->problems[0]);
        $this->assertSame(0, SalesOrder::count() - SalesOrder::where('channel', '!=', 'pos')->count());
    }

    public function test_not_enough_stock_waits_for_review_and_completes_on_retry(): void
    {
        $row = app(OfflineSaleSync::class)->ingest($this->sale(['lines' => [['product_id' => $this->gen->id, 'qty' => '500', 'price' => '25']], 'total' => '12500']), $this->cashier);
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $row->status);
        $this->assertStringContainsString('Not enough', $row->problems[0]);
        $this->assertSame(0, SalesOrder::where('channel', 'pos')->count()); // nothing half-posted

        app(\App\Services\InventoryService::class)->move($this->gen, Warehouse::find($this->main), '500', 'adjustment', null, $this->gen->cost, 'count found more');
        $row = app(OfflineSaleSync::class)->process($row);
        $this->assertSame(OfflineSale::COMPLETED, $row->status);
        $this->assertSame(2, $row->attempts);
    }

    public function test_account_sale_over_credit_limit_needs_an_admin_override(): void
    {
        $acct = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $acct->update(['credit_limit' => '10']);
        $row = app(OfflineSaleSync::class)->ingest($this->sale(['customer_id' => $acct->id, 'tender' => 'account']), $this->cashier);
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $row->status);
        $this->assertStringContainsString('Credit limit exceeded', $row->problems[0]);

        $owner = User::factory()->create(['role' => 'owner']);
        $row = app(OfflineSaleSync::class)->process($row, $owner, 'Customer paid by phone');
        $this->assertSame(OfflineSale::COMPLETED, $row->status);
        $this->assertSame('Customer paid by phone', $row->salesOrder->credit_override_reason);
        $this->assertSame('0.0000', $row->salesOrder->amount_paid); // charged to the account
    }

    public function test_server_compliance_check_still_applies(): void
    {
        $in = Customer::where('code', 'CTEST-IN')->firstOrFail();
        $cig = Product::where('sku', 'TST-CIG-001')->firstOrFail();
        $row = app(OfflineSaleSync::class)->ingest($this->sale([
            'customer_id' => $in->id, 'fulfillment' => 'delivery', 'ship_to_id' => $in->shipTos()->value('id'),
            'lines' => [['product_id' => $cig->id, 'qty' => '1', 'price' => '62.50']], 'total' => '62.50',
        ]), $this->cashier);

        $this->assertSame(OfflineSale::NEEDS_REVIEW, $row->status);
        $this->assertStringContainsString('Compliance check failed', $row->problems[0]);
        $this->assertStringContainsString('TST-CIG-001', $row->problems[0]);
    }

    public function test_price_and_total_differences_are_noted_on_completed_sales(): void
    {
        $row = app(OfflineSaleSync::class)->ingest($this->sale(['lines' => [['product_id' => $this->gen->id, 'qty' => '2', 'price' => '24.00']], 'total' => '50.00']), $this->cashier);

        $this->assertSame(OfflineSale::COMPLETED, $row->status);
        $this->assertSame('48.0000', $row->salesOrder->total); // the price the customer actually paid
        $this->assertStringContainsString('sold offline at $24.00; current price $25.00', $row->warnings[0]);
        $this->assertStringContainsString('differs from its lines', $row->warnings[1]);
    }

    public function test_handled_outside_the_system(): void
    {
        $row = app(OfflineSaleSync::class)->ingest($this->sale(['tender' => 'card']), $this->cashier);
        $owner = User::factory()->create(['role' => 'owner']);
        $row = app(OfflineSaleSync::class)->resolve($row, $owner, 'Ran the card on the terminal and entered it by hand');

        $this->assertSame(OfflineSale::RESOLVED, $row->status);
        $this->assertSame(OfflineSale::RESOLVED, app(OfflineSaleSync::class)->process($row)->status); // never re-run
    }

    public function test_sync_endpoint_batches_results_and_locks_out_signed_out_devices(): void
    {
        $ok = $this->sale();
        $card = $this->sale(['tender' => 'card']);

        $this->postJson(route('pos.offline.sync'), ['device_id' => 'tablet-1', 'sales' => [$ok]])->assertUnauthorized();

        $res = $this->actingAs($this->cashier)->postJson(route('pos.offline.sync'), ['device_id' => 'tablet-1', 'sales' => [$ok, $card, ['uuid' => 'nope']]])
            ->assertOk()->json('results');
        $this->assertSame('completed', $res[0]['status']);
        $this->assertStringStartsWith('POS', $res[0]['number']);
        $this->assertSame('needs_review', $res[1]['status']);
        $this->assertSame('rejected', $res[2]['status']);

        $this->actingAs($this->cashier)->postJson(route('pos.offline.sync'), ['sales' => array_fill(0, 26, $ok)])->assertUnprocessable();
    }

    public function test_review_list_and_retry_action(): void
    {
        $row = app(OfflineSaleSync::class)->ingest($this->sale(['lines' => [['product_id' => $this->gen->id, 'qty' => '500', 'price' => '25']]]), $this->cashier);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(ListOfflineSales::class)->assertOk()
            ->assertCanSeeTableRecords([$row])->assertSee('Needs review')->assertSee('Not enough');

        app(\App\Services\InventoryService::class)->move($this->gen, Warehouse::find($this->main), '500', 'adjustment', null, $this->gen->cost, 'found');
        Livewire::test(ListOfflineSales::class)->callTableAction('retry', $row)->assertHasNoTableActionErrors();
        $this->assertSame(OfflineSale::COMPLETED, $row->fresh()->status);
    }
}
