<?php

namespace Tests\Feature;

use App\Enums\SalesOrderStatus;
use App\Exceptions\UnbalancedJournalException;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\KySubjobberReport;
use App\Filament\Pages\KyTobaccoDistributorReport;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\DeliveryManifest;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\TaxFiling;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Reports\TaxReport;
use App\Services\KyExcise;
use App\Services\PostingService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use App\Services\Sequence;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreSeeder;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class Phase2Test extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = Warehouse::where('code', 'MAIN')->first();
    }

    private function bal(string $code): string
    {
        $id = Account::where('code', $code)->value('id');

        return bcsub((string) JournalLine::where('account_id', $id)->sum('debit'), (string) JournalLine::where('account_id', $id)->sum('credit'), 4);
    }

    private function po(Product $p, string $qty, string $cost, ?Vendor $v = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(['vendor_id' => ($v ?? Vendor::factory()->create())->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_cost' => $cost]);

        return $po;
    }

    private function sale(Product $p, string $qty, string $state = 'KY', string $type = 'retailer'): SalesOrder
    {
        $c = Customer::factory()->create(['type' => $type]);
        CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => $state, 'type' => $state === 'KY' ? 'tobacco_retail' : 'tobacco_wholesale']);
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => $state]);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);
        $svc = app(SalesOrderService::class);
        $svc->confirm($so);
        $svc->deliver($so);

        return $so->fresh();
    }

    // ---- 1. roles ----
    public function test_role_permissions(): void
    {
        $paths = ['/admin/customers', '/admin/sales-orders', '/admin/inventories', '/admin/purchase-orders', '/admin/delivery-manifests', '/admin/journals', '/admin/users', '/admin/reports/ky-subjobber', '/admin/tax-rates', '/pos'];
        $expect = [
            'sales' => ['/admin/customers', '/admin/sales-orders', '/pos'],
            'warehouse' => ['/admin/inventories', '/admin/purchase-orders', '/admin/delivery-manifests'],
            'driver' => ['/admin/delivery-manifests'],
            'admin' => $paths, 'owner' => $paths, 'brandworx_admin' => $paths,
        ];
        foreach ($expect as $role => $ok) {
            $u = User::factory()->create(['role' => $role]);
            foreach ($paths as $p) {
                $this->actingAs($u)->get($p)->assertStatus(in_array($p, $ok, true) ? 200 : 403);
            }
        }
        $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/admin/purchase-orders/create')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => false]))->get('/admin')->assertForbidden();
    }

    /** 0.17.2: only another BrandWorx admin (no longer the owner) can deactivate a BrandWorx admin. */
    public function test_only_brandworx_admin_can_deactivate_brandworx_admin(): void
    {
        $bw = User::factory()->create(['role' => 'brandworx_admin']);
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($admin);
        $this->assertFalse(UserResource::canEdit($bw));
        Livewire::test(ListUsers::class)->assertTableActionHidden('toggleActive', $bw);

        $this->actingAs($owner);
        Livewire::test(ListUsers::class)->assertTableActionHidden('toggleActive', $bw);

        $bw2 = User::factory()->create(['role' => 'brandworx_admin']);
        $this->actingAs($bw2);
        Livewire::test(ListUsers::class)->callTableAction('toggleActive', $bw);
        $this->assertFalse($bw->fresh()->active);
        $this->actingAs($bw->fresh())->get('/admin')->assertForbidden();
    }

    // ---- 2. KY excise ----
    public function test_ky_excise_math(): void
    {
        $x = app(KyExcise::class);
        $snuff = Product::factory()->make(['tax_class' => 'snuff_chew', 'smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 5]);
        $this->assertSame('0.9500', $x->smokelessTax($snuff, 1));
        $this->assertSame(2, KyExcise::snuffIncrements('2.0'));     // 1.5 + portion
        $this->assertSame(1, KyExcise::snuffIncrements('1.5'));
        $chew = fn ($oz) => (string) $x->smokelessUnitTax(Product::factory()->make(['tax_class' => 'snuff_chew', 'smokeless_type' => 'chew', 'net_weight_oz' => $oz]));
        $this->assertSame('0.190000', $chew('3.0'));
        $this->assertSame('0.400000', $chew('4.0'));
        $this->assertSame('0.400000', $chew('8.0'));
        $this->assertSame('0.650000', $chew('12'));
        $this->assertSame('1.030000', $chew('21'));  // 0.65 + 2 x 0.19
        $this->assertSame('0.060000', (string) $x->rate(KyExcise::PREMIUM_CIGAR_PCT, '2026-07-01'));
        $this->expectException(\RuntimeException::class);
        $x->rate(KyExcise::PREMIUM_CIGAR_PCT, '2026-06-30');
    }

    public function test_tax_rate_seeder_idempotent_and_keeps_edits(): void
    {
        $n = TaxRate::count();
        TaxRate::where('tax_type', KyExcise::SNUFF)->update(['rate' => '0.200000', 'needs_verification' => false]);
        $this->seed(TaxRateSeeder::class);
        $this->assertSame($n, TaxRate::count());
        $this->assertSame('0.200000', TaxRate::where('tax_type', KyExcise::SNUFF)->value('rate'));
    }

    public function test_excise_accrued_on_receipt_from_unlicensed_vendor_only(): void
    {
        $snuff = Product::factory()->create(['tax_class' => 'snuff_chew', 'pact_covered' => true, 'smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 5]);
        $mfr = Vendor::factory()->create(['is_manufacturer' => true, 'ky_tobacco_licensed' => false]);
        app(PurchaseOrderService::class)->receive($this->po($snuff, '10', '18', $mfr));
        $this->assertSame('9.5000', bcmul('-1', $this->bal('2100'), 4)); // 10 rolls x 5 cans x $0.19
        $this->assertSame('189.5000', $this->bal('1200'));

        $kyDist = Vendor::factory()->create(['ky_tobacco_licensed' => true]);
        app(PurchaseOrderService::class)->receive($this->po($snuff, '10', '18', $kyDist));
        $this->assertSame('-9.5000', $this->bal('2100'), 'no accrual when supplier is KY licensed');
        $this->assertSame(0, bccomp((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'), 4));
    }

    // ---- 5. small gaps ----
    public function test_partial_po_receiving(): void
    {
        $p = Product::factory()->create();
        $po = $this->po($p, '10', '2');
        $svc = app(PurchaseOrderService::class);
        $line = $po->lines()->first();
        $svc->receive($po, [$line->id => '4']);
        $this->assertSame('partial', $po->fresh()->status);
        $this->assertSame('4.0000', Inventory::where('product_id', $p->id)->value('qty'));
        try {
            $svc->receive($po->fresh(), [$line->id => '7']);
            $this->fail('over-receive allowed');
        } catch (WorkflowException) {
        }
        $svc->receive($po->fresh());
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('10.0000', Inventory::where('product_id', $p->id)->value('qty'));
        $this->assertSame('20.0000', $this->bal('1200'));
    }

    public function test_sequence_numbers_unique_and_continue_old_numbers(): void
    {
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id]);
        DB::table('sales_orders')->insert(['number' => 'SO000007', 'customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now(), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $a = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $b = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $this->assertSame('SO000008', $a->number);
        $this->assertSame('SO000009', $b->number);
        $this->assertSame('PO000001', Sequence::next('PO', PurchaseOrder::class));
    }

    public function test_manifest_stop_delivered_delivers_order(): void
    {
        $p = Product::factory()->create();
        app(PurchaseOrderService::class)->receive($this->po($p, '5', '1'));
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id]);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => '2', 'unit_price' => '3']);
        app(SalesOrderService::class)->confirm($so);

        $m = DeliveryManifest::create(['delivery_date' => now(), 'warehouse_id' => $this->wh->id]);
        $this->assertStringStartsWith('DM', $m->number);
        $stop = $m->stops()->create(['sales_order_id' => $so->id, 'sequence' => 1]);
        $this->assertSame(SalesOrderStatus::Confirmed, $so->fresh()->status);
        $stop->update(['status' => 'delivered', 'received_by' => 'TEST Clerk']);
        $this->assertSame(SalesOrderStatus::Invoiced, /* 0.20 */ $so->fresh()->status);
        $this->assertNotNull($stop->fresh()->delivered_at);
        $this->assertSame('3.0000', Inventory::where('product_id', $p->id)->value('qty'));
    }

    public function test_journal_reversal(): void
    {
        $post = app(PostingService::class);
        $j = $post->post('2026-10-01', 'TEST', [['1000', '50', 0], ['3000', 0, '50']]);
        $r = $post->reverse($j, 'test');
        $this->assertSame($j->id, $r->reversal_of_id);
        $this->assertSame('0.0000', $this->bal('1000'));
        $this->assertNotNull($j->fresh()->reversed_at);
        $this->expectException(UnbalancedJournalException::class);
        $post->reverse($j->fresh());
    }

    // ---- 3. reports ----
    public function test_subjobber_report(): void
    {
        $cig = Product::factory()->create(['tax_class' => 'cigarette_stamped', 'pact_covered' => true, 'sticks_per_unit' => 200, 'price' => '62.50']);
        app(PurchaseOrderService::class)->receive($this->po($cig, '10', '55', Vendor::factory()->create(['fein' => '00-0000000'])));
        $this->sale($cig, '3', 'KY', 'retailer');
        $this->sale($cig, '2', 'KY', 'subjobber');

        $r = TaxReport::for('ky_subjobber_cigarette')->build(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());
        $this->assertCount(1, $r['sections']['receipts']['rows']);
        $this->assertSame('1B', $r['sections']['receipts']['rows'][0][0]);
        $this->assertSame('2000', $r['sections']['receipts']['rows'][0][13]);
        $scheds = array_column($r['sections']['disbursements']['rows'], 0);
        sort($scheds);
        $this->assertSame(['2B', '2C'], $scheds);
        $this->assertSame('1000', $r['summary'][2][2]);
        $this->assertSame('1000', $r['summary'][4][2]); // ending
        $this->assertStringContainsString('Resident Subjobber', TaxReport::for('ky_subjobber_cigarette')->toCsv($r));
        $this->assertStringStartsWith('%PDF', TaxReport::for('ky_subjobber_cigarette')->toPdf($r));
    }

    public function test_tobacco_distributor_report_lines(): void
    {
        $snuff = Product::factory()->create(['tax_class' => 'snuff_chew', 'smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 5, 'price' => '24']);
        $chew = Product::factory()->create(['tax_class' => 'snuff_chew', 'smokeless_type' => 'chew', 'net_weight_oz' => '3', 'units_per_case' => 12, 'price' => '30']);
        $pipe = Product::factory()->create(['tax_class' => 'pipe', 'price' => '10']);
        $paid = Product::factory()->create(['tax_class' => 'pipe', 'price' => '10', 'ky_tax_paid_by_supplier' => true]);
        foreach ([$snuff, $chew, $pipe, $paid] as $p) {
            app(PurchaseOrderService::class)->receive($this->po($p, '20', '5'));
        }
        $this->sale($snuff, '2');      // 10 cans x 1 increment
        $this->sale($chew, '1');       // 12 single units
        $this->sale($pipe, '3');       // $30
        $this->sale($paid, '3');       // excluded
        $this->sale($pipe, '1', 'IL', 'wholesaler'); // out of state excluded

        $r = TaxReport::for('ky_tobacco_distributor')->build(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());
        $lines = collect($r['summary'])->keyBy(0);
        $this->assertSame('30.00', $lines['1'][2]);
        $this->assertSame('4.50', $lines['5'][2]);
        $this->assertSame('10', $lines['8'][2]);
        $this->assertSame('1.90', $lines['12'][2]);
        $this->assertSame('2.28', $lines['15'][2]);
        $this->assertSame('8.68', $lines['16'][2]);
        $this->assertSame('8.68', $r['tax_due']);
        $this->assertCount(3, $r['sections']['detail']['rows']);
    }

    public function test_report_page_saves_draft_and_respects_filed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $page = Livewire::test(KyTobaccoDistributorReport::class)->set('period', now()->format('Y-m'))->callAction('save');
        $f = TaxFiling::first();
        $this->assertSame('draft', $f->status);
        $f->update(['status' => 'filed']);
        $page->callAction('save');
        $this->assertSame(1, TaxFiling::count());
        $this->assertSame('filed', $f->fresh()->status);
        Livewire::test(KySubjobberReport::class)->assertOk()->callAction('csv')->assertFileDownloaded();
        $this->get('/admin/tax-filings')->assertOk();
    }
}
