<?php

namespace Tests\Feature;

use App\Imports\Types\CustomersImport;
use App\Livewire\Portal\Catalog;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Msa\MulticatBuilder;
use App\Portal\PortalService;
use App\Services\SalesOrderService;
use App\Support\CustomerType;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.3: portal Promotions / New / Featured sections, Buy again, catalog by category, fixed customer types. */
class Phase203PortalSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
    }

    private function contact(string $code = 'CTEST-KY'): CustomerContact
    {
        $c = Customer::where('code', $code)->first();
        $k = CustomerContact::create(['customer_id' => $c->id, 'name' => 'Pat Buyer', 'email' => strtolower($code).'@example.test']);
        $k->setPasswordFromLink('Secret12345');
        Auth::guard('portal')->login($k->fresh());

        return $k->fresh();
    }

    private function loc(CustomerContact $k)
    {
        return SalesOrder::pickupLocation(Warehouse::where('code', 'MAIN')->value('id'), $k->customer_id);
    }

    public function test_version(): void
    {
        $this->assertTrue(version_compare(config('erp.version'), '0.20.3', '>='));
    }

    public function test_sections_follow_flags_and_new_until_and_promotions(): void
    {
        $k = $this->contact();
        $gen = Product::where('sku', 'TST-GEN-001')->first();
        $pip = Product::where('sku', 'TST-PIP-001')->first();
        $cgr = Product::where('sku', 'TST-CGR-001')->first();
        $gen->update(['portal_featured' => true]);
        $pip->update(['portal_new' => true]);
        $cgr->update(['portal_new' => true, 'portal_new_until' => now()->subDay()->toDateString()]);
        Promotion::create(['name' => 'P', 'value' => 10, 'product_id' => $gen->id, 'starts_on' => now()->subDay(), 'ends_on' => now()->addDay()]);
        $s = app(PortalService::class)->sections($k, $this->loc($k));
        $ids = fn ($k) => $s[$k]->map(fn ($r) => $r['product']->id)->all();
        $this->assertSame([$gen->id], $ids('featured'));
        $this->assertSame([$gen->id], $ids('promotions'), 'active product promotion shows in Promotions');
        $this->assertSame([$pip->id], $ids('new'), 'expired new-until is hidden');
        // promo price (lowest wins) is the price shown
        $this->assertLessThan((float) $gen->price, (float) $s['promotions'][0]['price']);
        $gen->update(['active' => false]);
        $this->assertEmpty(app(PortalService::class)->sections($k, $this->loc($k))['featured']);
    }

    public function test_flag_change_is_audited(): void
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $p->update(['portal_promo' => true]);
        $log = ActivityLog::where('subject_type', Product::class)->where('subject_id', $p->id)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('portal_promo', json_encode($log->toArray()));
    }

    public function test_buy_again_lists_past_purchases_and_reorder_all(): void
    {
        $k = $this->contact();
        $svc = app(SalesOrderService::class);
        $so = SalesOrder::create(['customer_id' => $k->customer_id, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'fulfillment' => 'pickup', 'order_date' => now()->toDateString()]);
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $so->lines()->create(['product_id' => $p->id, 'qty' => 3, 'unit_price' => $p->price]);
        $svc->confirm($so->fresh());
        $svc->deliver($so->fresh());
        $rows = app(PortalService::class)->buyAgain($k, $this->loc($k));
        $this->assertCount(1, $rows);
        $this->assertSame($p->id, $rows[0]['product']->id);
        $this->assertEquals(3, (float) $rows[0]['last_qty']);

        $lw = Livewire::test(Catalog::class)->set('view', 'again')->assertSee('Buy again')->assertSee($p->name)->assertSee('Reorder all from '.$so->number)
            ->set('againQty.'.$p->id, 5)->call('addAgain', $p->id);
        $this->assertEquals(5, (float) $lw->get('cart')[$p->id]);
        $lw->call('reorderOrder', $so->id);
        $this->assertEquals(8, (float) $lw->get('cart')[$p->id]);
    }

    public function test_catalog_grouped_by_category_and_search_expands(): void
    {
        $this->contact();
        Livewire::test(Catalog::class)->assertSee('Expand all')->assertSee('Sundries')->assertSee('Pipe')
            ->set('q', 'Lighter')->assertSee('TEST Lighter Display')->assertDontSee('TEST Pipe Tobacco')->assertSeeHtml('open');
        Product::where('sku', 'TST-PIP-001')->update(['portal_featured' => true]);
        Livewire::test(Catalog::class)->assertSee('Featured');
    }

    public function test_customer_type_mapping_and_options(): void
    {
        $this->assertSame(['C-store', 'Liquor store', 'Smoke shop', 'Retailer', 'Restaurant', 'Food truck', 'Wholesale distributor', 'Other'], array_values(CustomerType::OPTIONS));
        foreach (['wholesaler' => 'wholesale_distributor', 'subjobber' => 'wholesale_distributor', 'distributor' => 'wholesale_distributor',
            'retailer' => 'retailer', 'Retail' => 'retailer', 'convenience store' => 'c_store', 'C-Store' => 'c_store', 'Liquor' => 'liquor_store',
            'vape shop' => 'smoke_shop', 'Food Truck' => 'food_truck', 'restaurant' => 'restaurant', 'xyz' => 'other', '' => 'other'] as $in => $out) {
            $this->assertSame($out, CustomerType::map($in), $in);
        }
        $this->assertTrue(CustomerType::isLicensee('wholesale_distributor'));
        $this->assertTrue(CustomerType::isLicensee('subjobber'));
        $this->assertFalse(CustomerType::isLicensee('c_store'));
        $this->assertSame('Distributor', MulticatBuilder::classOfTrade(new Customer(['type' => 'wholesale_distributor'])));
        $this->assertSame('Retailer', MulticatBuilder::classOfTrade(new Customer(['type' => 'smoke_shop'])));
    }

    public function test_migration_maps_legacy_types_and_is_rerunnable(): void
    {
        $id = DB::table('customers')->insertGetId(['code' => 'LEG1', 'name' => 'Legacy', 'type' => 'subjobber', 'terms' => 'COD', 'credit_limit' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id2 = DB::table('customers')->insertGetId(['code' => 'LEG2', 'name' => 'Legacy2', 'type' => 'weird', 'terms' => 'COD', 'credit_limit' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $m = require database_path('migrations/2026_11_18_000001_phase203_portal_sections.php');
        $m->up();
        $m->up();
        $this->assertSame('wholesale_distributor', DB::table('customers')->where('id', $id)->value('type'));
        $this->assertSame('subjobber', DB::table('customers')->where('id', $id)->value('type_legacy'));
        $this->assertSame('other', DB::table('customers')->where('id', $id2)->value('type'));
        $this->assertSame('weird', DB::table('customers')->where('id', $id2)->value('type_legacy'));
    }

    public function test_import_accepts_new_types_and_rejects_unknown(): void
    {
        $this->assertStringContainsString('Wholesale distributor', (new CustomersImport)->fields()['type']['label']);
    }
}
