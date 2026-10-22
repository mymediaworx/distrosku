<?php

namespace Tests\Feature;

use App\Filament\Resources\EligibilityRuleResource\Pages\ListEligibilityRules;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #7: narrow columns and phone layouts on admin lists (table config only). */
class Phase207ListLayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
    }

    public function test_products_phone_column_and_search_by_sku_and_name(): void
    {
        $cig = Product::where('sku', 'TST-CIG-001')->firstOrFail();
        $pipe = Product::where('sku', 'TST-PIP-001')->firstOrFail();

        Livewire::test(ListProducts::class)
            ->assertTableColumnExists('phone_summary')
            ->assertSee('TEST Cigarette Kings 20s...')         // phone column, name truncated
            ->searchTable('TST-CIG-001')->assertCanSeeTableRecords([$cig])->assertCanNotSeeTableRecords([$pipe]);

        Livewire::test(ListProducts::class)
            ->searchTable('Pipe Tobacco')->assertCanSeeTableRecords([$pipe])->assertCanNotSeeTableRecords([$cig]);
    }

    public function test_eligibility_rule_names_get_room_to_read(): void
    {
        Livewire::test(ListEligibilityRules::class)->assertOk()->assertSeeHtml('min-width:16rem');
    }

    public function test_purchase_order_secondary_actions_live_in_a_menu_and_still_work(): void
    {
        $po = PurchaseOrder::create(['number' => 'PO-LL'.uniqid(), 'vendor_id' => Vendor::factory()->create(['name' => 'TEST Very Long Wholesale Vendor Name LLC'])->id,
            'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now(), 'status' => 'ordered']);

        Livewire::test(ListPurchaseOrders::class)
            ->assertCanSeeTableRecords([$po])
            ->assertSeeHtml('min-width:12rem')
            ->assertTableActionExists('edit')
            ->assertTableActionExists('scanReceive')
            ->assertTableActionVisible('scanReceive', $po);
    }
}
