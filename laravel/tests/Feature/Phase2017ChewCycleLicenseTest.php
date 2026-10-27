<?php

namespace Tests\Feature;

use App\Filament\Pages\LicenseConflictReview;
use App\Livewire\Warehouse\Hub;
use App\Licensing\LicenseConflictScanner;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\CycleCountTask;
use App\Models\InventoryCount;
use App\Models\JournalLine;
use App\Models\LicenseConflict;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\KyExcise;
use App\Warehouse\CycleCountPlanner;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreSeeder;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.17: chew at 15% of wholesale, the cycle count plan, and license conflicts review. */
class Phase2017ChewCycleLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    // ---- chew 15% ----

    public function test_chew_is_15_percent_of_wholesale_from_the_switch_date_and_seeder_is_rerunnable(): void
    {
        $this->seed(TaxRateSeeder::class);
        $this->seed(TaxRateSeeder::class);
        $this->assertSame(1, TaxRate::where('jurisdiction', 'KY')->where('tax_type', KyExcise::CHEW_PCT)->count());
        $row = TaxRate::where('tax_type', KyExcise::CHEW_PCT)->first();
        $this->assertTrue((bool) $row->needs_verification);
        $row->update(['effective_from' => '2026-12-01']); // an edited/verified date survives a re-seed
        $this->seed(TaxRateSeeder::class);
        $this->assertSame('2026-12-01', TaxRate::where('tax_type', KyExcise::CHEW_PCT)->first()->effective_from->toDateString());
        $row->update(['effective_from' => '2026-11-01']);

        $x = app(KyExcise::class);
        $chew = Product::factory()->make(['tax_class' => 'snuff_chew', 'smokeless_type' => 'chew', 'net_weight_oz' => '3.0', 'units_per_case' => 1]);
        $this->assertFalse($x->chewIsPercent('2026-10-31'));
        $this->assertTrue($x->chewIsPercent('2026-11-01'));
        $this->assertEqualsWithDelta(0.19, (float) $x->smokelessTax($chew, 1, '2026-10-31', '10'), 0.00001);     // old per-unit rate before the switch
        $this->assertEqualsWithDelta(1.5, (float) $x->smokelessTax($chew, 1, '2026-11-02', '10'), 0.00001);      // 15% of wholesale after
        $this->assertEqualsWithDelta(0.0, (float) $x->smokelessTax($chew, 5, '2026-11-02', '0'), 0.00001);
        $snuff = Product::factory()->make(['tax_class' => 'snuff_chew', 'smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 1]);
        $this->assertEquals($x->smokelessUnitTax($snuff, '2026-10-31'), $x->smokelessUnitTax($snuff, '2026-11-05')); // snuff unchanged
    }

    public function test_po_receipt_accrues_chew_excise_at_15_percent_after_the_switch_and_old_rate_before(): void
    {
        $this->seed(TaxRateSeeder::class);
        $wh = Warehouse::where('code', 'MAIN')->first();
        $chew = Product::factory()->create(['tax_class' => 'snuff_chew', 'smokeless_type' => 'chew', 'net_weight_oz' => '3.0', 'units_per_case' => 1, 'cost' => '5', 'price' => '8']);
        $v = \App\Models\Vendor::factory()->create(['ky_tobacco_licensed' => false]);
        $recv = function () use ($wh, $chew, $v) {
            $po = \App\Models\PurchaseOrder::create(['vendor_id' => $v->id, 'warehouse_id' => $wh->id, 'order_date' => now()]);
            $po->lines()->create(['product_id' => $chew->id, 'qty' => 10, 'unit_cost' => '5']);

            return app(\App\Services\PurchaseOrderService::class)->receive($po)->lines()->first()->excise_accrued;
        };
        TaxRate::where('tax_type', KyExcise::CHEW_PCT)->update(['effective_from' => now()->addYear()->toDateString()]);
        $this->assertEqualsWithDelta(1.9, (float) $recv(), 0.0001);   // 10 x $0.19 per-unit rate (not yet switched)
        TaxRate::where('tax_type', KyExcise::CHEW_PCT)->update(['effective_from' => now()->subDay()->toDateString()]);
        $this->assertEqualsWithDelta(7.5, (float) $recv(), 0.0001);   // 15% of 10 x $5 wholesale
    }

    // ---- cycle count plan ----

    private function stock(string $sku, array $attrs = [], int $qty = 10): Product
    {
        $p = Product::factory()->create(['sku' => $sku, 'cost' => '2', 'price' => '5'] + $attrs);
        app(InventoryService::class)->move($p, Warehouse::where('code', 'MAIN')->first(), $qty, 'receipt');

        return $p;
    }

    public function test_plan_ranks_by_risk_velocity_and_recency_and_links_to_ordinary_counts(): void
    {
        $wh = Warehouse::where('code', 'MAIN')->first();
        $plain = $this->stock('PLAIN-1', ['tax_class' => 'general']);
        $risky = $this->stock('RISKY-1', ['tax_class' => 'cigarette_stamped']);
        $fast = $this->stock('FAST-1', ['tax_class' => 'general']);
        $c = Customer::factory()->create();
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => null, 'warehouse_id' => $wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $fast->id, 'qty' => 5, 'unit_price' => 5, 'line_total' => 25]);
        SalesOrder::whereKey($so->id)->update(['status' => 'invoiced', 'delivered_at' => now()->subDays(3)]);

        $planner = app(CycleCountPlanner::class);
        $ranked = $planner->rank($wh, CarbonImmutable::today());
        $this->assertSame($risky->id, $ranked->first()['product_id']);
        $this->assertSame('high_risk', $ranked->first()['reason']);
        $byId = $ranked->keyBy('product_id');
        $this->assertSame('A', $byId[$fast->id]['abc']);
        $this->assertSame('C', $byId[$plain->id]['abc']);
        $this->assertGreaterThan($byId[$plain->id]['score'], $byId[$fast->id]['score']);

        Setting::put('cycle_plan_size', '2');
        $made = $planner->generate($wh);
        $this->assertCount(2, $made);
        $this->assertSame(2, $planner->generate($wh)->count()); // idempotent: same (warehouse, product, date)
        $this->assertSame(2, CycleCountTask::count());

        $user = User::factory()->create(['role' => 'warehouse']);
        $count = $planner->startCount(CycleCountTask::where('status', 'open')->get(), $user);
        $this->assertInstanceOf(InventoryCount::class, $count);
        $this->assertSame('counting', $count->status);
        $this->assertSame(2, CycleCountTask::where('status', 'counting')->where('inventory_count_id', $count->id)->count());
        $glBefore = JournalLine::count();

        $planner->sync();
        $this->assertSame(2, CycleCountTask::where('status', 'counting')->count()); // still being counted
        $count->update(['status' => 'submitted', 'submitted_at' => now()]);
        $planner->sync();
        $this->assertSame(2, CycleCountTask::where('status', 'counted')->count());
        $this->assertSame($glBefore, JournalLine::count()); // the plan itself never posts to the GL

        // products with a task in flight / a count open are not planned again
        $this->assertSame(0, $planner->rank($wh, CarbonImmutable::today())->whereIn('product_id', CycleCountTask::pluck('product_id'))->count());
    }

    public function test_plan_respects_the_enabled_switch_and_weekly_schedule(): void
    {
        $wh = Warehouse::where('code', 'MAIN')->first();
        $this->stock('W-1');
        Setting::put('cycle_plan_enabled', '0');
        $this->assertSame(0, app(CycleCountPlanner::class)->ensureCurrent());
        Setting::put('cycle_plan_enabled', '1');
        Setting::put('cycle_plan_frequency', 'weekly');
        Setting::put('cycle_plan_weekday', '1');
        $tue = CarbonImmutable::parse('2026-10-27'); // Tuesday
        $this->assertSame('2026-10-26', CycleCountPlanner::dueDate($tue)->toDateString());
        $this->assertGreaterThan(0, app(CycleCountPlanner::class)->ensureCurrent($tue));
        $this->assertSame(0, app(CycleCountPlanner::class)->ensureCurrent($tue)); // already ran this period
    }

    public function test_hub_shows_todays_tasks_and_can_skip_and_take_them(): void
    {
        $this->stock('H-1', ['name' => 'Hub Widget']);
        $user = User::factory()->create(['role' => 'warehouse']);
        $this->actingAs($user);
        Livewire::test(Hub::class)->assertSeeHtml('data-test="cycle-tasks"')->assertSee('Hub Widget');
        $t = CycleCountTask::first();
        $this->assertNotNull($t);
        Livewire::test(Hub::class)->call('takeTask', $t->id);
        $this->assertSame($user->id, $t->fresh()->assigned_to);
        Livewire::test(Hub::class)->call('skipTask', $t->id);
        $this->assertSame('skipped', $t->fresh()->status);
    }

    public function test_artisan_command_generates_tasks(): void
    {
        $this->stock('CMD-1');
        $this->artisan('warehouse:cycle-plan', ['--force' => true])->assertSuccessful();
        $this->assertGreaterThan(0, CycleCountTask::count());
    }

    // ---- license conflicts ----

    public function test_scanner_detects_each_kind_idempotently_and_clears_resolved_causes(): void
    {
        $c1 = Customer::factory()->create(['name' => 'Alpha Mart', 'state_tax_id' => 'TX-1']);
        $c2 = Customer::factory()->create(['name' => 'Beta Mart', 'state_tax_id' => 'tx1']);
        Customer::factory()->create(['name' => 'Twin Shop']);
        Customer::factory()->create(['name' => 'Twin  Shop']);
        $expired = CustomerLicense::factory()->create(['customer_id' => $c1->id, 'expires_at' => now()->subMonth()->toDateString()]);
        $noProof = CustomerLicense::factory()->create(['customer_id' => $c2->id, 'number' => null, 'site_id' => null]);
        $orderOnly = Customer::factory()->create(['name' => 'Unlicensed Co']);
        $so = SalesOrder::create(['customer_id' => $orderOnly->id, 'ship_to_id' => null, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        SalesOrder::whereKey($so->id)->update(['status' => 'invoiced']);

        $s = app(LicenseConflictScanner::class);
        $r = $s->scan();
        $kinds = LicenseConflict::pluck('kind')->unique()->sort()->values()->all();
        $this->assertSame(['duplicate_name', 'duplicate_tax_id', 'expired', 'missing_license', 'no_proof'], $kinds);
        $this->assertSame($r['found'], LicenseConflict::count());
        $again = $s->scan();
        $this->assertSame(0, $again['new']);
        $this->assertSame($r['found'], LicenseConflict::count());

        $conf = LicenseConflict::where('kind', 'expired')->first();
        $s->resolve($conf, 'approved', 'verified with KY ABC', User::factory()->create(['role' => 'sales']));
        $s->scan();
        $this->assertSame('approved', $conf->fresh()->status); // a decision is never reopened

        $expired->update(['expires_at' => now()->addYear()->toDateString()]);
        $noProof->update(['number' => 'N-1']);
        $s->scan();
        $this->assertSame(0, LicenseConflict::where('kind', 'no_proof')->where('status', 'pending')->count());
        $this->assertSame(1, LicenseConflict::where('kind', 'no_proof')->where('status', 'cleared')->count());
    }

    public function test_review_screen_steps_one_by_one_and_saves_edits_with_documents(): void
    {
        $c1 = Customer::factory()->create(['name' => 'Gamma']);
        $l1 = CustomerLicense::factory()->create(['customer_id' => $c1->id, 'number' => null, 'site_id' => null]);
        $c2 = Customer::factory()->create(['name' => 'Delta']);
        $l2 = CustomerLicense::factory()->create(['customer_id' => $c2->id, 'number' => null, 'site_id' => null]);
        app(LicenseConflictScanner::class)->scan();
        $user = User::factory()->create(['role' => 'sales']);
        $this->actingAs($user);

        $page = Livewire::test(LicenseConflictReview::class);
        $first = $page->get('conflictId');
        $this->assertNotNull($first);
        $page->call('skip');
        $second = $page->get('conflictId');
        $this->assertNotSame($first, $second); // skipped one is not shown straight back
        $this->assertSame('skipped', LicenseConflict::find($first)->status);

        // edits need a Site ID or license number
        $page->set('data.state', 'KY')->set('data.type', 'tobacco_retail')->set('data.site_id', '')->set('data.number', '')->call('saveEdits');
        $this->assertSame('pending', LicenseConflict::find($second)->status);

        $page->set('data.site_id', '12345678')->set('data.research_note', 'Confirmed on KY ABC portal')->call('saveEdits');
        $edited = LicenseConflict::find($second);
        $this->assertSame('edited', $edited->status);
        $lic = CustomerLicense::find($edited->license_id);
        $this->assertSame('12345678', $lic->site_id);
        $this->assertSame('Confirmed on KY ABC portal', $lic->research_note);

        $this->assertSame($first, $page->get('conflictId')); // skipped item returns at the end
        $page->call('approve');
        $this->assertNull($page->get('conflictId'));
    }

    public function test_review_screen_is_restricted_and_license_documents_cast_to_array(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'driver']));
        $this->assertFalse(LicenseConflictReview::canAccess());
        $this->get(LicenseConflictReview::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->get(LicenseConflictReview::getUrl())->assertOk();

        $l = CustomerLicense::factory()->create();
        $l->update(['documents' => ['license-documents/a.pdf', 'license-documents/b.png']]);
        $this->assertSame(['license-documents/a.pdf', 'license-documents/b.png'], $l->fresh()->documents);
    }

    public function test_logging_defaults_to_daily_rotation(): void
    {
        $this->assertSame('daily', config('logging.channels.daily.driver'));
        $this->assertStringContainsString("'LOG_STACK', 'daily'", file_get_contents(config_path('logging.php')));
        $this->assertStringContainsString('LOG_DAILY_DAYS=14', file_get_contents(base_path('.env.example')));
    }
}
