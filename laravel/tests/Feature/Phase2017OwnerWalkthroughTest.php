<?php

namespace Tests\Feature;

use App\Analytics\DroppedCustomers;
use App\Filament\Pages\Dunning;
use App\Filament\Pages\Reports\CustomersDroppedOff;
use App\Filament\Pages\Settings;
use App\Filament\Resources\ProductResource\RelationManagers\VendorItemsRelationManager;
use App\Filament\Widgets\KpiStats;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DunningBatch;
use App\Models\DunningNotice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.17: items from the owner walkthrough (dashboard, Company Settings, nav, statement header, dunning, TV board). */
class Phase2017OwnerWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    private function order(Customer $c, int $daysAgo, string $status = 'invoiced'): SalesOrder
    {
        $when = now()->subDays($daysAgo);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => null, 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => $when->toDateString()]);
        SalesOrder::whereKey($so->id)->update(['status' => $status, 'invoiced_at' => $status === 'invoiced' ? $when : null, 'created_at' => $when]);

        return $so;
    }

    public function test_dropped_off_windows_exclusions_and_report(): void
    {
        $a = Customer::factory()->create(['name' => 'Old A']);      // last bought 100d ago
        $b = Customer::factory()->create(['name' => 'Fresh B']);    // 10d ago
        $c = Customer::factory()->create(['name' => 'Never C']);    // no orders
        $d = Customer::factory()->create(['name' => 'Inactive D', 'active' => false]);
        $e = Customer::factory()->create(['name' => 'Draft E']);    // only a cancelled order
        $f = Customer::factory()->create(['name' => 'Ancient F']);  // 200d
        $this->order($a, 100);
        $this->order($b, 10);
        $this->order($d, 200);
        $this->order($e, 300, 'cancelled');
        $this->order($f, 200);

        $counts = DroppedCustomers::counts();
        $this->assertSame([60 => 2, 90 => 2, 120 => 1, 180 => 1], $counts);
        $r = DroppedCustomers::report(90);
        $names = array_column($r['rows'], 1);
        $this->assertEqualsCanonicalizing(['Old A', 'Ancient F'], $names);
        $this->assertSame(['Ancient F'], array_column(DroppedCustomers::report(120)['rows'], 1));
        $this->assertSame(90, DroppedCustomers::normalizeWindow(7)); // unknown window falls back
    }

    public function test_kpi_tiles_click_through_and_dropped_tile_links_to_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $html = Livewire::test(KpiStats::class)->html();
        $this->assertStringContainsString('Customers dropped off', $html);
        $this->assertStringContainsString('reports/customers-dropped-off', $html);
        $this->assertStringContainsString('reports/ar-aging', $html);
        $m = new \ReflectionMethod(KpiStats::class, 'getStats');
        $m->setAccessible(true);
        $stats = $m->invoke(new KpiStats);
        $this->assertNotEmpty($stats);
        foreach ($stats as $s) {
            $this->assertNotEmpty($s->getUrl(), 'KPI tile without a link: '.$s->getLabel());
        }
    }

    public function test_dropped_off_report_page_and_window_filter(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $a = Customer::factory()->create(['name' => 'Lapsed Lou']);
        $this->order($a, 130);
        $this->get(CustomersDroppedOff::getUrl(['window' => 120]))->assertOk()->assertSee('Lapsed Lou');
        $this->get(CustomersDroppedOff::getUrl(['window' => 180]))->assertOk()->assertDontSee('Lapsed Lou');
    }

    public function test_company_settings_title_tabs_and_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->get(Settings::getUrl())->assertOk()->assertSee('Company Settings')->assertSee('B2B Customer Portal')->assertSee('Cycle count plan')->assertDontSee('Payment gateway'); // secrets stay owner-only
        $sales = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sales);
        $this->get(Settings::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get(Settings::getUrl())->assertOk()->assertSee('Payment gateway');
        $this->assertTrue(Settings::canAccess());
        $this->assertTrue(Settings::canSeeSecrets());
    }

    public function test_company_profile_saves_and_brand_route_redirects(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Settings::class)
            ->fillForm(['company_name' => 'Vega Wholesale', 'company_address_line1' => '12 Market St', 'company_city' => 'Lexington', 'company_state' => 'KY', 'company_postal_code' => '40507', 'company_phone' => '859-555-0100',
                'portal_enabled' => false])
            ->call('save')->assertHasNoFormErrors();
        $c = Company::current()->fresh();
        $this->assertSame('12 Market St', $c->address_line1);
        $this->assertSame('859-555-0100', $c->phone);
        $this->assertFalse(\App\Support\PortalSettings::enabled());
        $this->get('/admin/branding')->assertRedirect();
    }

    public function test_portal_switch_blocks_the_portal_with_a_message(): void
    {
        Setting::put('portal_enabled', '0');
        $res = $this->get('/portal/login');
        $res->assertStatus(503);
        Setting::put('portal_enabled', '1');
        $this->get('/portal/login')->assertOk();
    }

    public function test_statement_header_has_address_phone_and_logo_spacing(): void
    {
        Company::current()->update(['name' => 'Vega Wholesale', 'address_line1' => '12 Market St', 'city' => 'Lexington', 'state' => 'KY', 'postal_code' => '40507', 'phone' => '859-555-0100']);
        $html = view('docs._head', ['docTitle' => 'STATEMENT', 'company' => Company::current(), 'info' => 'x'])->render();
        $this->assertStringContainsString('12 Market St', $html);
        $this->assertStringContainsString('Lexington, KY 40507', $html);
        $this->assertStringContainsString('Phone: 859-555-0100', $html);
        $this->assertStringContainsString('height:10px', file_get_contents(resource_path('views/docs/_head.blade.php'))); // spacer between logo and name
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $c = Customer::factory()->create();
        $this->get(route('docs.statement', $c))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_vendor_bills_and_payments_live_in_purchasing(): void
    {
        foreach ([\App\Filament\Resources\VendorBillResource::class, \App\Filament\Resources\VendorPaymentResource::class, \App\Filament\Pages\Reports\ApAging::class] as $cls) {
            $r = new \ReflectionProperty($cls, 'navigationGroup');
            $r->setAccessible(true);
            $this->assertSame('Purchasing', $r->getValue(), $cls);
        }
        $this->assertFileDoesNotExist(app_path('Filament/Pages/CompanyBranding.php'));
    }

    public function test_product_page_lists_vendor_item_numbers(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $v = Vendor::factory()->create(['name' => 'Acme']);
        $p = Product::factory()->create();
        VendorItem::create(['vendor_id' => $v->id, 'product_id' => $p->id, 'vendor_item_no' => 'ACME-123']);
        $this->assertContains(VendorItemsRelationManager::class, \App\Filament\Resources\ProductResource::getRelations());
        Livewire::test(VendorItemsRelationManager::class, ['ownerRecord' => $p, 'pageClass' => \App\Filament\Resources\ProductResource\Pages\EditProduct::class])
            ->assertSee('ACME-123');
    }

    public function test_dunning_notice_pdf_and_email_not_configured_notice(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        config(['mail.default' => 'log']);
        $this->assertTrue(Dunning::emailNotConfigured());
        $this->get(Dunning::getUrl())->assertOk()->assertSee('Email is not configured')->assertSee('Print / PDF');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        $this->assertFalse(Dunning::emailNotConfigured());
        $this->get(Dunning::getUrl())->assertOk()->assertDontSee('Email is not configured');

        $c = Customer::factory()->create(['name' => 'Late Larry']);
        $b = DunningBatch::create(['as_of' => now()->toDateString()]);
        $n = DunningNotice::create(['dunning_batch_id' => $b->id, 'customer_id' => $c->id, 'level' => 1, 'max_days_overdue' => 20, 'overdue_amount' => '10.00', 'balance' => '10.00',
            'to_email' => null, 'subject' => 'Friendly reminder', 'body' => "Hello\n\nPay up.", 'invoices' => []]);
        $pdf = Dunning::noticePdf($n);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_pickup_board_tv_is_full_screen_without_navigation(): void
    {
        $this->get(route('pickup.tv'))->assertRedirect(); // guests go to login
        $this->actingAs(User::factory()->create(['role' => 'driver']));
        $this->get(route('pickup.tv'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $res = $this->get(route('pickup.tv'))->assertOk();
        $res->assertSee('http-equiv="refresh"', false);
        $res->assertDontSee('fi-sidebar', false)->assertDontSee('fi-topbar', false);
    }
}
