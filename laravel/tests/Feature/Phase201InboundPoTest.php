<?php

namespace Tests\Feature;

use App\Filament\Pages\ExpectedInbound;
use App\Filament\Pages\Modules;
use App\Filament\Resources\PurchaseOrderResource\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Filament\Widgets\ExpectedInboundWidget;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Navigation\NavOrder;
use App\Purchasing\InboundTracking;
use App\Purchasing\ReceivingAlerts;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.1: inbound PO tracking, receiving-team alerts, Expected inbound page/widget, nav order. */
class Phase201InboundPoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $wh;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
        $this->wh = User::factory()->create(['role' => 'warehouse', 'active' => true]);
        $this->main = Warehouse::where('code', 'MAIN')->first();
        $this->actingAs($this->owner);
    }

    private function po(array $attrs = []): PurchaseOrder
    {
        $po = PurchaseOrder::create($attrs + ['vendor_id' => Vendor::first()?->id ?? Vendor::factory()->create()->id, 'warehouse_id' => $this->main->id, 'order_date' => now()->toDateString()]);
        $po->lines()->create(['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'qty' => 10, 'unit_cost' => 2]);

        return $po->fresh();
    }

    public function test_tracking_links_per_carrier_custom_and_multiple(): void
    {
        $po = $this->po(['carrier_code' => 'ups', 'tracking_numbers' => ['1Z999', '1Z888']]);
        $links = InboundTracking::links($po);
        $this->assertSame('https://www.ups.com/track?tracknum=1Z999', $links[0]['url']);
        $this->assertCount(2, $links);
        foreach (['fedex' => 'https://www.fedex.com/fedextrack/?trknbr=', 'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
            'dhl' => 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id='] as $c => $u) {
            $po->carrier_code = $c;
            $this->assertSame($u.'1Z999', InboundTracking::links($po)[0]['url']);
        }
        $po->forceFill(['carrier_code' => 'other', 'carrier_name' => 'Estes', 'tracking_url' => null]);
        $this->assertSame([], InboundTracking::links($po));
        $this->assertSame('Estes', InboundTracking::carrierLabel($po));
        $po->tracking_url = 'https://freight.example/t/{tracking}';
        $this->assertSame('https://freight.example/t/1Z888', InboundTracking::links($po)[1]['url']);
        $po->tracking_url = 'javascript:alert(1)';
        $this->assertSame([], InboundTracking::links($po));
        $this->assertStringContainsString('target="_blank"', InboundTracking::html($this->po(['carrier_code' => 'fedex', 'tracking_numbers' => ['77']])));
    }

    public function test_alert_on_sent_and_on_tracking_change_to_receiving_team(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'active' => true]);
        $po = $this->po();
        $this->assertSame(0, $this->wh->notifications()->count()); // draft: no alert
        $po->update(['status' => 'ordered']);
        $this->assertSame(1, $this->wh->notifications()->count());
        $this->assertSame(0, $sales->notifications()->count());
        $this->assertNotNull($po->fresh()->ordered_at);
        $this->assertStringContainsString('sent to vendor', $this->wh->notifications()->first()->data['title']);

        $po->fresh()->update(['carrier_code' => 'ups', 'tracking_numbers' => ['1Z1'], 'expected_at' => now()->addDay()->toDateString()]);
        $this->assertSame(2, $this->wh->notifications()->count());
        $this->assertTrue(ActivityLog::where('event', 'po_receiving_alert')->exists());
        $this->assertTrue(ActivityLog::where('subject_type', PurchaseOrder::class)->where('subject_id', $po->id)->where('event', 'updated')->exists());

        $po->fresh()->update(['notes' => 'x']); // unrelated field: no alert
        $this->assertSame(2, $this->wh->notifications()->count());
    }

    public function test_per_warehouse_receivers_override_role_and_no_mail_needed(): void
    {
        Mail::fake();
        $picked = User::factory()->create(['role' => 'warehouse', 'active' => true]);
        $this->main->receivers()->sync([$picked->id]);
        $this->po(['status' => 'ordered']);
        $this->assertSame(1, $picked->notifications()->count());
        $this->assertSame(0, $this->wh->notifications()->count());
        Mail::assertNothingSent(); // MAIL_MAILER=array => database only
    }

    public function test_falls_back_to_admins_when_no_warehouse_users(): void
    {
        User::where('role', 'warehouse')->update(['active' => false]);
        $this->po(['status' => 'ordered']);
        $this->assertGreaterThan(0, $this->owner->notifications()->count());
    }

    public function test_morning_digest_and_schedule(): void
    {
        $this->po(['status' => 'ordered', 'expected_at' => now()->subDay()->toDateString()]);
        $this->po(['status' => 'ordered', 'expected_at' => now()->toDateString()]);
        $this->po(['status' => 'ordered', 'expected_at' => now()->addDays(10)->toDateString()]);
        $before = $this->wh->notifications()->count();
        Artisan::call('po:inbound-digest');
        $this->assertSame($before + 1, $this->wh->notifications()->count());
        $n = $this->wh->notifications()->get()->first(fn ($x) => str_starts_with($x->data['title'], 'Expected inbound'))->data;
        $this->assertStringContainsString('1 today, 0 tomorrow, 1 overdue', $n['title']);
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->command->implode(' ');
        $this->assertStringContainsString('po:inbound-digest', $events);
    }

    public function test_expected_inbound_page_widget_and_po_screens(): void
    {
        $late = $this->po(['status' => 'ordered', 'expected_at' => now()->subDays(2)->toDateString(), 'carrier_code' => 'usps', 'tracking_numbers' => ['9400']]);
        $this->po(['status' => 'draft']);
        $this->assertSame('overdue', ReceivingAlerts::bucket($late));
        $this->actingAs($this->wh);
        Livewire::test(ExpectedInbound::class)->assertCanSeeTableRecords([$late])->assertSee('Overdue')->assertSee('TST-GEN-001')->assertSee('Start receiving');
        $this->assertSame(1, ExpectedInbound::query()->count());
        $this->assertTrue(ExpectedInboundWidget::canView());
        Livewire::test(ExpectedInboundWidget::class)->assertSee($late->number)->assertSee('tools.usps.com', false);
        $this->get(route('wh.receive', $late))->assertOk()->assertSee('Track 9400');
        $this->get(route('wh.hub'))->assertOk()->assertSee('Track 9400');

        $this->actingAs($this->owner);
        Livewire::test(ListPurchaseOrders::class)->assertSee('Track 9400');
        Livewire::test(EditPurchaseOrder::class, ['record' => $late->id])->assertSee('Inbound shipping')
            ->fillForm(['tracking_numbers' => ['9400', '9401']])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['9400', '9401'], $late->fresh()->tracking_numbers);
    }

    public function test_migration_rerunnable(): void
    {
        $m = require database_path('migrations/2026_11_17_000001_phase201_po_inbound.php');
        $m->up();
        $m->up();
        $this->assertTrue(\Schema::hasTable('warehouse_receivers'));
    }

    public function test_modules_and_sidebar_order(): void
    {
        $this->assertSame(['POS', 'Sales Orders', 'Customers', 'Payments', 'Pricing', 'Promotions', 'Products', 'Vendors', 'Purchasing', 'Warehouse', 'Inventory', 'Counter Pickup',
            'Delivery', 'Driver', 'Van Sales', 'Accounting', 'Reports', 'Tax Filings', 'MSA Weekly', 'PACT Reports', 'Compliance', 'Users', 'Company Settings', 'Imports'],
            array_column(Modules::definitions(), 0));
        $this->assertSame(NavOrder::flat(), array_column(Modules::definitions(), 0));
        $labels = array_column(Modules::tiles(), 'label');
        $this->assertSame(array_values(array_intersect(NavOrder::flat(), $labels)), $labels);
        $this->assertSame(0, Modules::section('POS'));
        $this->assertSame(1, Modules::section('Delivery'));
        $this->assertSame(3, Modules::section('Imports'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertSame(NavOrder::GROUPS, array_values(array_map(fn ($g) => is_string($g) ? $g : $g->getLabel(), Filament::getCurrentPanel()->getNavigationGroups())));
        foreach (array_merge(Filament::getCurrentPanel()->getResources(), Filament::getCurrentPanel()->getPages()) as $c) {
            $g = $c::getNavigationGroup();
            $this->assertTrue($g === null || in_array($g, NavOrder::GROUPS, true), "$c group $g not in NavOrder");
        }
        $this->assertSame('Purchasing', ExpectedInbound::getNavigationGroup());
        $this->assertSame('Payments', \App\Filament\Resources\PaymentResource::getNavigationGroup());
        $this->assertSame('Counter Pickup', \App\Filament\Pages\CounterPickupBoard::getNavigationGroup());
        $this->assertSame(0, Modules::section('Counter Pickup'));
        $this->assertSame('Purchasing', \App\Filament\Resources\VendorBillResource::getNavigationGroup()); // 0.20.17: moved out of the old Bills (AP) group
        $this->assertSame('Purchasing', \App\Filament\Resources\VendorPaymentResource::getNavigationGroup());
        $this->assertNotContains('Bills (AP)', NavOrder::GROUPS);
        $this->get('/admin')->assertOk();
        $html = $this->get('/admin/modules')->assertOk()->getContent();
        $pos = fn ($s) => strpos($html, '<span>'.$s.'</span>');
        $this->assertTrue($pos('POS') < $pos('Sales Orders') && $pos('Inventory') < $pos('Counter Pickup') && $pos('Counter Pickup') < $pos('Delivery') && $pos('Compliance') < $pos('Users') && $pos('Company Settings') < $pos('Imports'));
    }
}
