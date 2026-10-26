<?php

namespace Tests\Feature;

use App\Filament\Resources\OfflineSaleResource\Pages\ListOfflineSales;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Pos\OfflineSaleSync;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.11 (#26): offline sales below cost, well below current price, or without a saved-price age go to Needs review. */
class Phase2011OfflinePriceRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $gen;   // cost 15, list 25

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->cashier = User::factory()->create(['role' => 'sales']);
        $this->gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
    }

    private function sale(string $price, array $over = []): array
    {
        return array_replace([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->cashier->id,
            'sold_at' => now()->subHours(2)->toIso8601String(), 'catalog_at' => now()->subHours(5)->toIso8601String(),
            'customer_id' => Customer::where('code', 'CASH')->value('id'), 'warehouse_id' => (int) Warehouse::where('code', 'MAIN')->value('id'),
            'fulfillment' => 'pickup', 'tender' => 'cash', 'total' => (string) (2 * (float) $price),
            'lines' => [['product_id' => $this->gen->id, 'qty' => '2', 'price' => $price]],
        ], $over);
    }

    private function ingest(array $sale): OfflineSale
    {
        return app(OfflineSaleSync::class)->ingest($sale, $this->cashier, 'tablet-1');
    }

    public function test_below_cost_goes_to_review(): void
    {
        $row = $this->ingest($this->sale('14.99'));
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $row->status);
        $this->assertNull($row->sales_order_id);
        $this->assertStringContainsString('below cost', implode(' ', $row->problems));
    }

    public function test_well_below_current_price_goes_to_review_and_threshold_is_configurable(): void
    {
        $row = $this->ingest($this->sale('19.99')); // > 20% below 25
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $row->status);
        $this->assertStringContainsString('more than 20% below the current price $25.00', implode(' ', $row->problems));

        $this->assertSame(OfflineSale::COMPLETED, $this->ingest($this->sale('20.00'))->status); // exactly at the line

        config(['erp.offline_sales.max_below_price_pct' => 10]);
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $this->ingest($this->sale('22.00'))->status);
        $this->assertSame(OfflineSale::COMPLETED, $this->ingest($this->sale('22.50'))->status);
    }

    public function test_missing_saved_price_age_goes_to_review(): void
    {
        $sale = $this->sale('25.00');
        unset($sale['catalog_at']);
        $row = $this->ingest($sale);
        $this->assertSame(OfflineSale::NEEDS_REVIEW, $row->status);
        $this->assertStringContainsString("didn't send how old its saved prices were", implode(' ', $row->problems));

        $this->assertSame(OfflineSale::NEEDS_REVIEW, $this->ingest($this->sale('25.00', ['catalog_at' => 'garbage']))->status);
    }

    public function test_normal_price_still_completes_and_plain_retry_keeps_it_held(): void
    {
        $this->assertSame(OfflineSale::COMPLETED, $this->ingest($this->sale('25.00'))->status);

        $row = $this->ingest($this->sale('10.00'));
        $this->assertSame(OfflineSale::NEEDS_REVIEW, app(OfflineSaleSync::class)->process($row)->status);
    }

    public function test_admin_approves_prices_and_sale_completes_with_audit(): void
    {
        $row = $this->ingest($this->sale('10.00'));
        $admin = User::where('role', 'admin')->first() ?? User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(ListOfflineSales::class)
            ->callTableAction('approvePrices', $row, ['reason' => 'Clearance price agreed by owner'])
            ->assertHasNoTableActionErrors();

        $row->refresh();
        $this->assertSame(OfflineSale::COMPLETED, $row->status);
        $this->assertNotNull($row->sales_order_id);
        $this->assertTrue(ActivityLog::where('event', 'offline_sale_prices_approved')->exists());
    }

    public function test_sales_role_cannot_approve_prices(): void
    {
        $row = $this->ingest($this->sale('10.00'));
        $this->actingAs($this->cashier);
        Livewire::test(ListOfflineSales::class)->assertTableActionHidden('approvePrices', $row);
    }
}
