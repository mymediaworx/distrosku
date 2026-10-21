<?php

namespace Tests\Feature;

use App\Compliance\Eligibility;
use App\Compliance\KyHempRegistry;
use App\Delivery\VanSaleService;
use App\Exceptions\ComplianceException;
use App\Filament\Pages\KyHempRegistryPage;
use App\Livewire\CounterPos;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\EligibilityRule;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StateRegistryEntry;
use App\Models\User;
use App\Models\VapeDirectoryListing;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class Phase181KyRegistryTest extends TestCase
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
        $p = Product::create($a + ['sku' => 'TST-V'.uniqid(), 'brand' => 'Mellow Fellow Fun', 'name' => 'Mellow Fellow - Disposable - 2ML - Delta 8 - Blueberry OG',
            'tax_class' => 'general', 'unit' => 'each', 'price' => 10, 'cost' => 4, 'product_subtype' => 'hemp', 'product_form' => 'vape']);
        Inventory::create(['product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'qty' => 50, 'reserved' => 0]);

        return $p;
    }

    private function order(Product $p, ?Customer $c = null, array $a = []): SalesOrder
    {
        $c ??= $this->ky;
        $so = SalesOrder::create($a + ['customer_id' => $c->id, 'ship_to_id' => $c->shipTos()->value('id'), 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => 2, 'unit_price' => 10]);

        return $so->fresh();
    }

    private function rows(): array
    {
        return KyHempRegistry::parseHtml(file_get_contents(base_path('tests/fixtures/ky-hemp-registry-report.html')))[0];
    }

    private function bigRows(): array
    {
        $extra = array_map(fn ($i) => ['record_id' => (string) (9000 + $i), 'brand' => 'Filler', 'product_name' => "Filler gummy $i", 'product_type' => 'Gummy'], range(1, 10));

        return array_merge($this->rows(), $extra);
    }

    public function test_seeded_rule_enabled_block_ky_with_source(): void
    {
        $r = EligibilityRule::where('key', 'ky_thc_vape_registry')->first();
        $this->assertFalse($r->enabled); // 0.18.2: off until CHFS lists UPCs
        $this->assertStringContainsString('DISABLED until CHFS lists UPCs', $r->source_note);
        $this->assertSame('block', $r->action);
        $this->assertSame('KY', $r->state);
        $this->assertSame('thc_vape', $r->selector);
        $this->assertTrue($r->needs_vape_directory);
        $this->assertStringContainsString(KyHempRegistry::URL, $r->source_note);
        (include database_path('migrations/2026_11_09_000001_phase181_ky_hemp_registry.php'))->up();
        $this->assertSame(1, EligibilityRule::where('key', 'ky_thc_vape_registry')->count());
        // 0.18.2 migration turns an enabled (0.18.1) rule off, once, and is re-run safe
        EligibilityRule::where('key', 'ky_thc_vape_registry')->update(['enabled' => true, 'source_note' => 'old note']);
        $m = include database_path('migrations/2026_11_10_000001_phase182_ky_registry_disabled.php');
        $m->up();
        $m->up();
        $r = $r->fresh();
        $this->assertFalse($r->enabled);
        $this->assertSame(1, substr_count($r->source_note, 'DISABLED until CHFS lists UPCs'));
        $this->assertStringContainsString('old note', $r->source_note);
        $this->assertFalse(EligibilityRule::where('key', 'federal_hemp_2026')->value('enabled'));
    }

    public function test_thc_vape_definition(): void
    {
        $this->assertTrue($this->product()->isThcVape());
        $this->assertTrue($this->product(['product_subtype' => 'vape', 'product_form' => null, 'hemp_cannabinoid' => true])->isThcVape());
        $this->assertTrue($this->product(['product_subtype' => 'vape', 'product_form' => null, 'thc_total_mg' => 900])->isThcVape());
        $this->assertTrue($this->product(['product_subtype' => 'cbd'])->isThcVape());
        $this->assertFalse($this->product(['product_subtype' => 'vape', 'product_form' => null])->isThcVape()); // nicotine vape
        $this->assertFalse($this->product(['product_form' => 'gummy'])->isThcVape());
    }

    public function test_parse_report_and_import_matches_exact(): void
    {
        $rows = $this->rows();
        $this->assertCount(4, $rows);
        $this->assertSame('Vape', $rows[2]['product_type']);
        $this->assertSame('612', $rows[2]['record_id']);
        $this->assertSame('Yes', $rows[2]['adult_use']);
        $hit = $this->product();
        $miss = $this->product(['name' => 'Some Other Cart 1g']);
        $close = $this->product(['name' => 'Mellow Fellow Disposable 2ML Delta 8 Strawberry Cough!']); // punctuation only = exact
        $near = $this->product(['name' => 'Mellow Fellow Disposable 2ML Delta 8 Strawbery']);
        $s = app(KyHempRegistry::class)->import($rows, 'test');
        $this->assertSame(4, $s['new']);
        $this->assertSame(2, $s['vapes']);
        $this->assertSame(2, $s['listed']);
        $this->assertTrue(VapeDirectoryListing::listed($hit, 'KY', now()->toDateString()));
        $this->assertTrue(VapeDirectoryListing::listed($close, 'KY', now()->toDateString()));
        $this->assertFalse(VapeDirectoryListing::listed($miss, 'KY', now()->toDateString()));
        $this->assertFalse(VapeDirectoryListing::listed($near, 'KY', now()->toDateString()));
        $this->assertSame('623', KyHempRegistry::suggestions($near)->first()['entry']->record_id);
        $this->assertTrue(ActivityLog::where('event', 'ky_registry_import')->exists());
        // re-import is idempotent
        $s = app(KyHempRegistry::class)->import($rows, 'test');
        $this->assertSame(0, $s['new']);
        $this->assertSame(0, $s['listed']);
        $this->assertSame(2, VapeDirectoryListing::count());
    }

    public function test_disabled_by_default_does_not_block(): void
    {
        $p = $this->product();
        $this->assertSame('confirmed', app(SalesOrderService::class)->confirm($this->order($p))->status->value);
        $this->assertSame([], app(\App\Services\ComplianceChecker::class)->checkLine($p, $this->ky, $this->ky->shipTos()->first()));
    }

    private function enableKy(): void
    {
        EligibilityRule::where('key', 'ky_thc_vape_registry')->update(['enabled' => true]);
    }

    public function test_confirm_blocks_unlisted_thc_vape_into_ky_only(): void
    {
        $this->enableKy();
        $p = $this->product();
        try {
            app(SalesOrderService::class)->confirm($this->order($p));
            $this->fail('confirmed');
        } catch (ComplianceException $e) {
            $this->assertStringContainsString('KY CHFS hemp-derived cannabinoid product registry', $e->getMessage());
        }
        app(SalesOrderService::class)->confirm($this->order($p, $this->in)); // IN unaffected
        $nic = $this->product(['product_subtype' => 'vape', 'product_form' => null, 'name' => 'Nicotine pod']);
        app(SalesOrderService::class)->confirm($this->order($nic)); // nicotine vape unaffected
        app(KyHempRegistry::class)->import($this->rows(), 'test');
        $this->assertSame('confirmed', app(SalesOrderService::class)->confirm($this->order($p))->status->value);
    }

    public function test_manual_toggle_and_link_and_removal(): void
    {
        $u = User::factory()->create(['role' => 'owner', 'active' => true]);
        $svc = app(KyHempRegistry::class);
        $p = $this->product(['name' => 'Unregistered cart']);
        $svc->setManual($p, true, now()->subDays(3)->toDateString(), $u, 'CHFS email');
        $this->assertTrue(VapeDirectoryListing::listed($p, 'KY', now()->toDateString()));
        $svc->setManual($p, false, now()->toDateString(), $u);
        $this->assertFalse(VapeDirectoryListing::listed($p, 'KY', now()->toDateString()));
        $this->assertTrue(VapeDirectoryListing::listed($p, 'KY', now()->subDay()->toDateString()));

        $svc->import($this->bigRows(), 'test');
        $q = $this->product(['name' => 'Blueberry thing']);
        $svc->link($q, StateRegistryEntry::where('record_id', '612')->first(), $u);
        $auto = $this->product();
        $this->assertSame(0, $svc->match()['listed'] - 1); // $auto listed by match
        // entry 612 removed from a (complete enough) snapshot: auto listing ends, the manual link stays
        $rows = array_values(array_filter($this->bigRows(), fn ($r) => $r['record_id'] !== '612'));
        $s = $svc->import($rows, 'test', null, now()->addDays(5));
        $this->assertSame(1, $s['removed']);
        $this->assertSame(1, $s['delisted']);
        $this->assertFalse(VapeDirectoryListing::listed($auto, 'KY', now()->addDays(5)->toDateString()));
        $this->assertTrue(VapeDirectoryListing::listed($q, 'KY', now()->addDays(5)->toDateString()));
        // a partial snapshot never removes
        $s = $svc->import(array_slice($this->rows(), 0, 1), 'partial');
        $this->assertSame(0, $s['removed']);
    }

    public function test_upload_csv(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ky').'.csv';
        file_put_contents($f, "Record ID,Brand of Product,Full name of the product,Type of product\n612,Mellow Fellow Fun,Mellow Fellow - Disposable - 2ML - Delta 8 - Blueberry OG,Vape\n");
        $rows = KyHempRegistry::readFile($f);
        $this->assertSame('Vape', $rows[0]['product_type']);
        $p = $this->product();
        $this->assertSame(1, app(KyHempRegistry::class)->import($rows, 'upload')['listed']);
        $this->assertTrue(VapeDirectoryListing::listed($p, 'KY', now()->toDateString()));
    }

    public function test_blocked_confirm_inside_rolled_back_transaction_is_still_logged(): void
    {
        $this->enableKy(); // logging still works when a rule blocks
        $p = $this->product();
        try {
            DB::transaction(function () use ($p) {
                $so = $this->order($p, null, ['channel' => 'pos', 'number' => 'POS-TEST-1']);
                app(SalesOrderService::class)->confirm($so);
            });
            $this->fail('confirmed');
        } catch (ComplianceException) {
        }
        $this->assertSame(0, SalesOrder::where('number', 'POS-TEST-1')->count());
        $log = ActivityLog::where('event', 'eligibility_blocked')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->new_values['rolled_back']);
        $this->assertSame('pos', $log->new_values['channel']);
        $this->assertNull($log->subject_id);
    }

    public function test_pos_and_van_blocked_attempts_logged(): void
    {
        $this->enableKy(); // logging still works when a rule blocks
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        $p = $this->product();
        Livewire::test(CounterPos::class)->set('customerId', $this->ky->id)->set('scan', $p->sku)->call('add')->call('checkout')->assertSee('Remove blocked items');
        $this->assertTrue(ActivityLog::where('event', 'pos_sale_blocked')->exists());
        try {
            $this->wh->update(['is_van' => true]);
            app(VanSaleService::class)->sell($this->wh, $this->ky, $this->ky->shipTos()->first(), [['product_id' => $p->id, 'qty' => 1]], 'cash');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('registry', $e->getMessage());
        }
        $this->assertTrue(ActivityLog::where('event', 'van_sale_blocked')->exists());
    }

    public function test_page_access(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        $this->product();
        $this->get('/admin/ky-hemp-registry')->assertOk()->assertSee('Not listed (rule off, not blocked)')->assertSee('disabled until CHFS lists UPCs');
        $this->enableKy();
        $this->get('/admin/ky-hemp-registry')->assertOk()->assertSee('Not listed: blocked into KY');
        Livewire::test(KyHempRegistryPage::class)->assertOk()->set('filter', 'all')->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        $this->get('/admin/ky-hemp-registry')->assertForbidden();
    }

    public function test_rules_checked_only_at_confirm(): void
    {
        // Eligibility is called from confirm() only; pick / deliver / invoice do not re-check.
        $src = file_get_contents(app_path('Services/SalesOrderService.php'));
        $this->assertSame(1, substr_count($src, 'Eligibility::class)->checkOrder'));
        $this->assertInstanceOf(Eligibility::class, app(Eligibility::class));
    }
}
