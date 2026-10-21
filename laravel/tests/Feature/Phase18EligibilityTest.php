<?php

namespace Tests\Feature;

use App\Compliance\Eligibility;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\ComplianceException;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\InventoryAtRisk;
use App\Filament\Resources\EligibilityRuleResource;
use App\Filament\Resources\EligibilityRuleResource\Pages\ListEligibilityRules;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\EligibilityRule;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCoa;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\VapeDirectoryListing;
use App\Models\Warehouse;
use App\Services\ComplianceChecker;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class Phase18EligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Customer $ky;

    private Customer $in;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
        $this->in = Customer::where('code', 'CTEST-IN')->first();
        $this->wh = Warehouse::where('code', 'MAIN')->first();
    }

    private function product(array $a = []): Product
    {
        $p = Product::create($a + ['sku' => 'TST-H'.uniqid(), 'name' => 'TEST Hemp Gummies', 'tax_class' => 'general', 'unit' => 'each', 'price' => 10, 'cost' => 4, 'product_subtype' => 'hemp']);
        Inventory::create(['product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'qty' => 50, 'reserved' => 0]);

        return $p;
    }

    private function rule(array $a = []): EligibilityRule
    {
        return EligibilityRule::create($a + ['name' => 'TEST rule', 'level' => 'state', 'state' => 'KY', 'action' => 'block', 'effective_from' => '2020-01-01', 'enabled' => true]);
    }

    private function order(Product $p, ?Customer $c = null, array $a = []): SalesOrder
    {
        $c ??= $this->ky;
        $so = SalesOrder::create($a + ['customer_id' => $c->id, 'ship_to_id' => $c->shipTos()->value('id'), 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => 2, 'unit_price' => 10]);

        return $so->fresh();
    }

    private function confirmFails(SalesOrder $so, string $needle): void
    {
        try {
            app(SalesOrderService::class)->confirm($so);
            $this->fail('confirmed');
        } catch (ComplianceException $e) {
            $this->assertStringContainsString($needle, json_encode($so->fresh()->compliance_reasons));
        }
        $this->assertSame(S::Draft, $so->fresh()->status);
    }

    public function test_seeded_system_rules(): void
    {
        $fed = EligibilityRule::where('key', 'federal_hemp_2026')->first();
        $this->assertFalse($fed->enabled);
        $this->assertTrue($fed->verify_with_counsel);
        $this->assertNull($fed->thc_total_max_mg);
        $this->assertSame('2026-12-11', $fed->effective_from->toDateString());
        $this->assertStringContainsString('verify with counsel', $fed->source_note);
        $this->assertTrue(EligibilityRule::where('key', 'msa_directory')->value('enabled'));
        // re-run safe
        (include database_path('migrations/2026_11_08_000001_phase18_eligibility.php'))->up();
        $this->assertSame(3, EligibilityRule::whereNotNull('key')->count()); // + 0.18.1 KY THC vape registry rule
    }

    public function test_state_synthetic_rule_blocks_ky_not_in(): void
    {
        $this->rule(['name' => 'KY synthetic ban', 'synthetic_fires' => true, 'subtype' => 'hemp']);
        $p = $this->product(['synthetic_cannabinoid' => true]);
        $this->confirmFails($this->order($p), 'synthetic cannabinoid');
        $this->assertSame(1, ActivityLog::where('event', 'eligibility_blocked')->count());
        $ok = app(SalesOrderService::class)->confirm($this->order($p, $this->in));
        $this->assertSame(S::Confirmed, $ok->status);
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($this->product()))->status); // not synthetic
    }

    public function test_effective_date_uses_order_and_ship_dates(): void
    {
        $this->rule(['name' => 'Future ban', 'effective_from' => now()->addDays(10)->toDateString()]);
        $p = $this->product();
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($p))->status);
        $this->confirmFails($this->order($p, null, ['ship_date' => now()->addDays(12)->toDateString()]), 'Future ban');
        $this->rule(['name' => 'Expired ban', 'effective_from' => '2020-01-01', 'effective_to' => '2021-01-01']);
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($p))->status);
    }

    public function test_thc_threshold_configurable_and_unknown(): void
    {
        $r = $this->rule(['name' => 'THC cap', 'subtype' => 'hemp', 'thc_total_max_mg' => 100]);
        $this->confirmFails($this->order($this->product(['thc_total_mg' => 150])), 'over the 100 mg limit');
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($this->product(['thc_total_mg' => 100])))->status);
        $unknown = $this->product();
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($unknown))->status);
        $r->update(['thc_unknown_fires' => true]);
        $this->assertSame(2, $r->fresh()->version);
        $this->confirmFails($this->order($unknown), 'not recorded');
        $r->update(['thc_unknown_fires' => false, 'thc_serving_max_mg' => 5]);
        $this->confirmFails($this->order($this->product(['thc_serving_mg' => 10, 'thc_total_mg' => 50])), 'per serving');
    }

    public function test_vape_fda_status_and_state_directory(): void
    {
        $this->rule(['name' => 'FDA vape', 'subtype' => 'vape', 'fda_statuses' => ['denied', 'none']]);
        $this->rule(['name' => 'KY vape directory', 'subtype' => 'vape', 'needs_vape_directory' => true]);
        $v = $this->product(['product_subtype' => 'vape', 'name' => 'TEST Vape', 'fda_status' => 'denied']);
        $this->confirmFails($this->order($v), 'Denied');
        $v->update(['fda_status' => 'authorized']);
        $this->confirmFails($this->order($v), 'not listed on the KY vape directory');
        VapeDirectoryListing::create(['state' => 'KY', 'product_id' => $v->id, 'listed_from' => '2020-01-01']);
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($v))->status);
        $v->update(['fda_status' => null]); // unset = none
        $this->confirmFails($this->order($v), 'None');
    }

    public function test_federal_applies_everywhere_and_county_city_scope(): void
    {
        $this->rule(['name' => 'Fed flavor', 'level' => 'federal', 'state' => null, 'flavored_fires' => true]);
        $p = $this->product(['flavored' => true]);
        $this->confirmFails($this->order($p, $this->in), 'Fed flavor');
        EligibilityRule::where('name', 'Fed flavor')->update(['enabled' => false]);
        $this->rule(['name' => 'Lexington only', 'city' => 'Lexington']);
        $this->confirmFails($this->order($this->product()), 'Lexington only');
        EligibilityRule::where('name', 'Lexington only')->update(['city' => 'Louisville']);
        $this->assertSame(S::Confirmed, app(SalesOrderService::class)->confirm($this->order($this->product()))->status);
    }

    public function test_warn_rule_saves_warning_and_confirms(): void
    {
        $this->rule(['name' => 'COA needed', 'action' => 'warn', 'needs_current_coa' => true, 'subtype' => 'hemp']);
        $p = $this->product();
        $so = app(SalesOrderService::class)->confirm($this->order($p));
        $this->assertSame(S::Confirmed, $so->status);
        $this->assertStringContainsString('no current COA', json_encode($so->fresh()->elig_warnings));
        ProductCoa::create(['product_id' => $p->id, 'lab' => 'TEST Lab', 'batch_lot' => 'B1', 'tested_on' => now()->subMonth(), 'expires_on' => now()->addDays(10)]);
        $so2 = app(SalesOrderService::class)->confirm($this->order($p));
        $this->assertNull($so2->fresh()->elig_warnings);
        $this->assertSame('expiring', ProductCoa::first()->state());
        $this->assertSame(1, ProductCoa::expiringSoon()->count());
        $this->assertFalse(ProductCoa::currentFor($p, now()->addDays(11)));
    }

    public function test_override_admin_only_with_reason_logged(): void
    {
        $this->rule(['name' => 'KY ban', 'subtype' => 'hemp']);
        $so = $this->order($this->product());
        $this->confirmFails($so, 'KY ban');
        $e = app(Eligibility::class);
        try {
            $e->override($so->fresh(), User::factory()->create(['role' => 'sales']), 'customer asked');
            $this->fail('sales overrode');
        } catch (WorkflowException) {
        }
        $this->assertSame(1, ActivityLog::where('event', 'eligibility_override_blocked')->count());
        $admin = User::factory()->create(['role' => 'owner']);
        try {
            $e->override($so->fresh(), $admin, ' ');
            $this->fail('no reason');
        } catch (WorkflowException) {
        }
        $e->override($so->fresh(), $admin, 'Counsel cleared this lot');
        $done = app(SalesOrderService::class)->confirm($so->fresh());
        $this->assertSame(S::Confirmed, $done->status);
        $log = ActivityLog::where('event', 'eligibility_override')->first();
        $this->assertNotNull($log);
        $this->assertSame('Counsel cleared this lot', $so->fresh()->elig_override_reason);
    }

    public function test_msa_runs_through_registry_and_can_be_disabled(): void
    {
        Setting::put('msa_directory_strict', '1');
        $cig = Product::create(['sku' => 'TST-CIG-X', 'name' => 'TEST Cig', 'tax_class' => 'cigarette_stamped', 'unit' => 'carton', 'price' => 50, 'brand' => 'TESTBRAND']);
        $r = app(ComplianceChecker::class)->checkLine($cig, $this->ky, $this->ky->shipTos()->first());
        $this->assertStringContainsString('No KY MSA directory list is loaded', implode(' ', $r));
        EligibilityRule::where('key', 'msa_directory')->update(['enabled' => false]);
        $this->assertSame([], app(ComplianceChecker::class)->checkLine($cig, $this->ky, $this->ky->shipTos()->first(), null));
    }

    public function test_pos_van_portal_precheck_includes_registry(): void
    {
        $this->rule(['name' => 'KY ban', 'subtype' => 'hemp']);
        $p = $this->product();
        $r = app(ComplianceChecker::class)->checkLine($p, $this->ky, $this->ky->shipTos()->first());
        $this->assertStringContainsString('KY ban', implode(' ', $r));
    }

    public function test_inventory_at_risk(): void
    {
        $p = $this->product(['synthetic_cannabinoid' => true]); // 50 on hand, cost 4
        $this->rule(['name' => 'KY synth cutoff', 'synthetic_fires' => true, 'effective_from' => now()->addDays(20)->toDateString()]);
        $so = $this->order($p);
        app(SalesOrderService::class)->confirm($so); // 2 sold in last 30 days
        $rows = app(Eligibility::class)->atRisk('KY', 120);
        $this->assertCount(1, $rows);
        $this->assertSame(20, $rows[0]['days_left']);
        $this->assertSame(200.0, $rows[0]['value']);
        $this->assertEqualsWithDelta(2 / 30, $rows[0]['sold_per_day'], 0.0001);
        $this->assertSame([], app(Eligibility::class)->atRisk('IN', 120));
        // the federal draft is only previewed when drafts are included
        Carbon::setTestNow('2026-11-01');
        $this->assertCount(0, array_filter(app(Eligibility::class)->atRisk('IN', 60), fn ($r) => $r['rule']->key === 'federal_hemp_2026'));
        $this->assertCount(1, array_filter(app(Eligibility::class)->atRisk('IN', 60, true), fn ($r) => $r['rule']->key === 'federal_hemp_2026'));
        Carbon::setTestNow();
    }

    public function test_admin_screens(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        Livewire::test(ListEligibilityRules::class)->assertOk()->assertSee('Federal hemp definition change');
        $this->assertFalse(EligibilityRuleResource::canDelete(EligibilityRule::where('key', 'msa_directory')->first()));
        $this->assertTrue(EligibilityRuleResource::canDelete($this->rule()));
        Livewire::test(InventoryAtRisk::class)->assertOk()->set('includeDrafts', true)->assertOk();
        $p = $this->product(['product_subtype' => 'vape']);
        Livewire::test(EditProduct::class, ['record' => $p->getRouteKey()])->assertOk()->assertSee('Total THC per container');
        $this->get('/admin/eligibility-rules/create')->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        $this->get('/admin/eligibility-rules')->assertForbidden();
        $this->get('/admin/inventory-at-risk')->assertForbidden();
    }
}
