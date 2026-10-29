<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Pages\KyTobaccoDistributorReport as TobaccoPage;
use App\Filament\Pages\ReturnDrill;
use App\Inventory\CountService;
use App\Inventory\ReorderService;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\TaxFiling;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WarehouseEvent;
use App\Models\Warehouse;
use App\Reports\KyCigaretteXml;
use App\Reports\KyTobaccoXml;
use App\Reports\TaxReport;
use App\Services\CreditMemoService;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use App\Tax\ReturnService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase13ReturnApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = Warehouse::where('code', 'MAIN')->first();
        $this->admin = User::factory()->create(['role' => 'admin']);
        foreach (['ky_filer_fein' => '12-3456789', 'ky_filer_license' => 'KY123456', 'ky_filer_name' => 'Vega Distribution LLC', 'ky_filer_address' => '1 Main St',
            'ky_filer_city' => 'Louisville', 'ky_filer_state' => 'KY', 'ky_filer_zip' => '40202', 'ky_rep_first' => 'Test', 'ky_rep_last' => 'Owner',
            'ky_rep_title' => 'Owner', 'ky_rep_phone' => '(502) 555-0100', 'ky_rep_email' => 'test@example.com'] as $k => $v) {
            Setting::put($k, $v);
        }
    }

    private function receive(Product $p, string $qty, string $cost, ?Vendor $v = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(['vendor_id' => ($v ?? Vendor::factory()->create(['fein' => '987654321', 'address' => '9 Supply Rd', 'city' => 'Nashville', 'state' => 'TN', 'zip' => '37201']))->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_cost' => $cost]);

        return app(PurchaseOrderService::class)->receive($po);
    }

    private function sale(Product $p, string $qty): SalesOrder
    {
        $c = Customer::factory()->create(['type' => 'retailer', 'fein' => '111222333']);
        CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'type' => 'tobacco_retail', 'number' => 'R-555-'.$c->id]);
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'address' => '5 Shop St', 'city' => 'Lexington', 'zip' => '40507']);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);
        $svc = app(SalesOrderService::class);
        $svc->confirm($so);
        $svc->deliver($so);
        $svc->invoice($so->fresh());

        return $so->fresh();
    }

    private function filing(): TaxFiling
    {
        $s = now()->startOfMonth();

        return TaxFiling::create(['report_type' => 'ky_tobacco_distributor', 'period_start' => $s->toDateString(), 'period_end' => $s->copy()->endOfMonth()->toDateString()]);
    }

    private function denied(\Closure $fn, string $needle = 'locks that month'): void
    {
        try {
            $fn();
            $this->fail('change allowed in a locked month');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_approval_locks_month_and_amendment_versions_with_amended_xml(): void
    {
        $pipe = Product::factory()->create(['tax_class' => 'pipe', 'price' => '10', 'cost' => '4']);
        $this->receive($pipe, '20', '4');
        $so = $this->sale($pipe, '5');
        $cms = app(CreditMemoService::class);
        $cms->post($cms->create($so, [['sales_order_line_id' => $so->lines->first()->id, 'qty' => '1', 'disposition' => 'restock']]));

        $f = $this->filing();
        $this->assertSame(1, $f->fresh()->version);
        $svc = app(ReturnService::class);
        $this->denied(fn () => $svc->approve($f, User::factory()->create(['role' => 'sales'])), 'owner or admin');
        $this->denied(fn () => $svc->markFiled($f, $this->admin, 'X'), 'Approve the return');
        $svc->approve($f, $this->admin);
        $f->refresh();
        $this->assertSame('approved', $f->status);
        $this->assertSame($this->admin->id, $f->approved_by);
        $this->assertSame([], KyTobaccoXml::validate($f->xml));
        $this->assertStringNotContainsString('AmendedReturnIndicator', $f->xml);
        $this->assertSame('50.00', collect($f->summary)->firstWhere(0, '1')[2]);

        // locked: invoices, invoice lines, credit memos and stock movements in the month
        $this->denied(fn () => $cms->post($cms->create($so->fresh(), [['sales_order_line_id' => $so->lines->first()->id, 'qty' => '1']])));
        $this->denied(fn () => $so->fresh()->update(['customer_id' => Customer::factory()->create()->id]));
        $this->denied(fn () => $so->lines->first()->update(['qty' => 4]));
        $this->denied(fn () => $this->receive($pipe, '1', '4'));
        // a different month is not affected
        TaxFiling::create(['report_type' => 'ky_tobacco_distributor', 'period_start' => now()->subMonths(3)->startOfMonth()->toDateString(), 'period_end' => now()->subMonths(3)->endOfMonth()->toDateString()]);

        $svc->markFiled($f, $this->admin, 'CONF-1');
        $this->assertSame('filed', $f->fresh()->status);
        $this->denied(fn () => $svc->amend($f->fresh(), $this->admin, '  '), 'reason');
        $v2 = $svc->amend($f->fresh(), $this->admin, 'Missed a credit memo');
        $this->assertSame(2, $v2->version);
        $this->assertSame($f->id, $v2->amends_id);
        $this->denied(fn () => $svc->amend($f->fresh(), $this->admin, 'again'), 'latest version');

        // month reopened by the amendment
        $cms->post($cms->create($so->fresh(), [['sales_order_line_id' => $so->lines->first()->id, 'qty' => '1', 'disposition' => 'restock']]));
        $svc->approve($v2, $this->admin);
        $v2->refresh();
        $this->assertSame('approved', $v2->status);
        $this->assertStringContainsString('<AmendedReturnIndicator>X</AmendedReturnIndicator>', $v2->xml);
        $this->assertSame([], KyTobaccoXml::validate($v2->xml));
        $this->assertSame('-20.00', collect($v2->summary)->firstWhere(0, '2')[2]);
        $this->assertNotNull($f->fresh()->superseded_at);
        $this->assertSame('filed', $f->fresh()->status); // history kept
        $this->denied(fn () => $this->receive($pipe, '1', '4'));

        // filing table actions
        $this->actingAs($this->admin);
        Livewire::test(\App\Filament\Resources\TaxFilingResource\Pages\ListTaxFilings::class)
            ->assertTableActionHidden('amend', $f->fresh())->assertTableActionVisible('amend', $v2)
            ->callTableAction('xml', $v2)->assertFileDownloaded("ky_tobacco_distributor_{$f->period_start->format('Y-m')}_v2_amended.xml");
    }

    public function test_invalid_xml_blocks_download_and_approval(): void
    {
        $pipe = Product::factory()->create(['tax_class' => 'pipe', 'price' => '10', 'cost' => '4']);
        $this->receive($pipe, '20', '4');
        $this->sale($pipe, '2');
        $this->actingAs($this->admin);
        $page = Livewire::test(TobaccoPage::class)->set('period', now()->format('Y-m'));
        $page->call('downloadXml')->assertFileDownloaded()->assertSet('xmlErrors', []);

        $pipe->update(['ky_state_desc' => 'KY-BOGUS']);
        $page = Livewire::test(TobaccoPage::class)->set('period', now()->format('Y-m'))->call('downloadXml');
        $this->assertNotEmpty($page->get('xmlErrors'));
        $page->assertSee('XML download blocked');
        $this->assertNull($page->effects['download'] ?? null);

        $f = $this->filing();
        $this->denied(fn () => app(ReturnService::class)->approve($f, $this->admin), 'does not validate');
        $this->assertSame('draft', $f->fresh()->status);
        Livewire::test(\App\Filament\Resources\TaxFilingResource\Pages\ListTaxFilings::class)->callTableAction('xml', $f);
        // nothing locked
        $this->receive($pipe, '1', '4');
    }

    public function test_drill_down_from_return_lines_to_documents(): void
    {
        $pipe = Product::factory()->create(['sku' => 'PIPE-1', 'tax_class' => 'pipe', 'price' => '10', 'cost' => '4']);
        $cig = Product::factory()->create(['sku' => 'CIG-1', 'tax_class' => 'cigarette_stamped', 'sticks_per_unit' => 200, 'price' => '62.50', 'upc' => '012345678905',
            'manufacturer' => 'Big Tobacco', 'manufacturer_fein' => '444555666', 'brand' => 'Marlboro', 'msa_status' => 'OPM']);
        $po = $this->receive($pipe, '20', '4');
        $this->receive($cig, '10', '55');
        $so = $this->sale($pipe, '5');
        $so2 = $this->sale($cig, '2');
        $cms = app(CreditMemoService::class);
        $cm = $cms->post($cms->create($so, [['sales_order_line_id' => $so->lines->first()->id, 'qty' => '1', 'disposition' => 'restock']]));

        $s = CarbonImmutable::now()->startOfMonth();
        $r = TaxReport::for('ky_tobacco_distributor')->build($s, $s->endOfMonth());
        $this->assertSame([$so->id], array_column($r['drill']['1'], 'id'));
        $this->assertSame(['cm'], array_column($r['drill']['2'], 'type'));
        $this->assertEqualsCanonicalizing(['so', 'cm'], array_column($r['drill']['3'], 'type'));
        foreach ($r['summary'] as [$code]) {
            if (! in_array($code, ['4', '11'], true)) { // rate lines
                $this->assertArrayHasKey($code, $r['drill'], "line {$code} has no drill-down");
            }
        }
        $c = TaxReport::for('ky_subjobber_cigarette')->build($s, $s->endOfMonth());
        $this->assertSame(['po'], array_unique(array_column($c['drill']['B'], 'type')));
        $this->assertSame([$so2->id], array_column($c['drill']['C'], 'id'));

        $this->actingAs($this->admin);
        $this->get(ReturnDrill::getUrl(['type' => 'ky_tobacco_distributor', 'period' => $s->format('Y-m'), 'line' => '3']))
            ->assertOk()->assertSee($so->number)->assertSee($cm->number)->assertSee(\App\Filament\Resources\SalesOrderResource::getUrl('edit', ['record' => $so->id]), false);
        $f = $this->filing();
        app(ReturnService::class)->approve($f, $this->admin);
        $this->get(ReturnDrill::getUrl(['filing' => $f->id, 'line' => '2']))->assertOk()->assertSee($cm->number)
            ->assertSee(\App\Filament\Resources\CreditMemoResource::getUrl('view', ['record' => $cm->id]), false);
        $this->get(ReturnDrill::getUrl(['type' => 'ky_subjobber_cigarette', 'period' => $s->format('Y-m'), 'line' => 'C']))->assertOk()->assertSee($so2->number);
        $this->get(ReturnDrill::getUrl(['type' => 'bogus', 'period' => 'x']))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'driver']))->get(ReturnDrill::getUrl(['filing' => $f->id]))->assertForbidden();
        $this->actingAs($this->admin);
        Livewire::test(TobaccoPage::class)->set('period', $s->format('Y-m'))->assertSee('tax-reports/drill', false);
    }

    public function test_cigarette_return_approval_validates_against_cig_schema(): void
    {
        $cig = Product::factory()->create(['tax_class' => 'cigarette_stamped', 'sticks_per_unit' => 200, 'price' => '62.50', 'upc' => '012345678905',
            'manufacturer' => 'Big Tobacco', 'manufacturer_fein' => '444555666', 'brand' => 'Marlboro', 'msa_status' => 'OPM']);
        $this->receive($cig, '10', '55');
        $this->sale($cig, '3');
        $s = now()->startOfMonth();
        $f = TaxFiling::create(['report_type' => 'ky_subjobber_cigarette', 'period_start' => $s->toDateString(), 'period_end' => $s->copy()->endOfMonth()->toDateString()]);
        app(ReturnService::class)->approve($f, $this->admin);
        $this->assertSame([], KyCigaretteXml::validate($f->fresh()->xml));
        $this->denied(fn () => $this->receive($cig, '1', '55'));
        // shipped XSDs are the docs/xsd-cig copy
        $docs = dirname(base_path()).'/docs/xsd-cig';
        if (is_dir($docs)) {
            foreach (glob($docs.'/*.xsd') ?: [] as $x) {
                $this->assertFileEquals($x, resource_path('ky-edi/KYCigarette2017V1.4/'.basename($x)));
            }
        }
    }

    public function test_pact_covered_defaults_on_for_covered_tax_classes_and_stays_editable(): void
    {
        foreach (['cigarette_stamped', 'ryo', 'snuff_chew', 'vapor'] as $cls) {
            $p = new Product(Product::factory()->raw(['tax_class' => $cls]));
            $p->save();
            $this->assertTrue($p->fresh()->pact_covered, $cls);
        }
        $this->assertFalse(Product::factory()->create(['tax_class' => 'cigar'])->fresh()->pact_covered);
        $this->assertFalse(Product::factory()->create(['tax_class' => 'ryo', 'pact_covered' => false])->fresh()->pact_covered); // explicit off kept
        $g = Product::factory()->create(['tax_class' => 'general']);
        $g->update(['tax_class' => 'vapor']);
        $this->assertTrue($g->fresh()->pact_covered);
        $g->update(['pact_covered' => false]);
        $g->update(['name' => 'Renamed']);
        $this->assertFalse($g->fresh()->pact_covered); // editable
        $this->actingAs($this->admin);
        Livewire::test(\App\Filament\Resources\ProductResource\Pages\CreateProduct::class)
            ->fillForm(['tax_class' => 'ryo'])->assertFormSet(['pact_covered' => true])
            ->fillForm(['pact_covered' => false])->assertFormSet(['pact_covered' => false]);
    }

    public function test_count_auto_approves_within_threshold_and_logs(): void
    {
        $inv = app(InventoryService::class);
        $cheap = Product::factory()->create(['sku' => 'CH-1', 'cost' => '2']);
        $dear = Product::factory()->create(['sku' => 'DR-1', 'cost' => '100']);
        $inv->move($cheap, $this->wh, 50, 'receipt');
        $inv->move($dear, $this->wh, 100, 'receipt');
        $svc = app(CountService::class);
        $counter = User::factory()->create(['role' => 'warehouse']);

        // cheap: -5 x $2 = $10 (<= $25); dear: -1 of 100 = 1% (<= 2%) -> auto
        $c = $svc->create($this->wh, 'full', false, [], $this->admin);
        $svc->start($c);
        $svc->setCounted($c->lines()->where('product_id', $cheap->id)->first(), 45, $counter);
        $svc->setCounted($c->lines()->where('product_id', $dear->id)->first(), 99, $counter);
        $c = $svc->submit($c->fresh(), false, $counter);
        $this->assertSame('posted', $c->status);
        $this->assertTrue($c->auto_approved);
        $this->assertNull($c->approved_by);
        $ev = WarehouseEvent::where('type', 'count_auto_approve')->first();
        $this->assertSame($c->number, $ev->details['count']);
        $this->assertCount(2, $ev->details['lines']);
        $this->assertNotNull($c->journal_id);

        // dear: -3 of 99 = 3% and $300 -> needs admin
        $c2 = $svc->create($this->wh, 'full', false, [], $this->admin);
        $svc->start($c2);
        $svc->setCounted($c2->lines()->where('product_id', $cheap->id)->first(), 45, $counter);
        $svc->setCounted($c2->lines()->where('product_id', $dear->id)->first(), 96, $counter);
        $c2 = $svc->submit($c2->fresh(), false, $counter);
        $this->assertSame('submitted', $c2->status);
        $this->assertFalse($c2->auto_approved);
        $svc->approve($c2, $this->admin);
        $this->assertSame('posted', $c2->fresh()->status);

        // thresholds are editable in Settings > Warehouse
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        Livewire::test(\App\Filament\Pages\Settings::class)->assertFormSet(['count_auto_approve_value' => 25, 'count_auto_approve_pct' => 2])
            ->fillForm(['count_auto_approve_value' => '500'])->call('save');
        $this->assertSame('500', Setting::get('count_auto_approve_value'));
        $c3 = $svc->create($this->wh, 'full', false, [], $this->admin);
        $svc->start($c3);
        $svc->setCounted($c3->lines()->where('product_id', $dear->id)->first(), 93, $counter);
        $c3 = $svc->submit($c3->fresh(), true, $counter);
        $this->assertSame('posted', $c3->status); // $300 now within $500
        $this->assertSame(2, InventoryCount::where('auto_approved', true)->count());
    }

    public function test_reorder_draft_po_uses_last_vendor_price_with_cost_fallback(): void
    {
        $v = Vendor::factory()->create(['name' => 'Alpha']);
        $other = Vendor::factory()->create(['name' => 'Other']);
        $p = Product::factory()->create(['sku' => 'RO-1', 'cost' => '3', 'reorder_point' => 10, 'preferred_vendor_id' => $v->id]);
        $q = Product::factory()->create(['sku' => 'RO-2', 'cost' => '7', 'reorder_point' => 10, 'preferred_vendor_id' => $v->id]);
        $old = PurchaseOrder::create(['vendor_id' => $v->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->subDays(40), 'status' => 'draft']);
        $old->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_cost' => '2.50']);
        $new = PurchaseOrder::create(['vendor_id' => $v->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->subDays(20), 'status' => 'draft']);
        $new->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_cost' => '2.75']);
        $o = PurchaseOrder::create(['vendor_id' => $other->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->subDays(1), 'status' => 'draft']);
        $o->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_cost' => '1.00']); // different vendor: ignored
        foreach ([$old, $new, $o] as $po) {
            $po->update(['status' => 'cancelled']);
        }
        $new->update(['status' => 'closed']);
        $old->update(['status' => 'closed']);
        $rs = app(ReorderService::class);
        $this->assertSame('2.7500', $rs->lastVendorPrice($p, $v));
        $this->assertSame('7.0000', $rs->lastVendorPrice($q, $v));
        $pos = $rs->createDraftPos($this->wh->id, [$v->id]);
        $lines = $pos[0]->lines()->get()->keyBy('product_id');
        $this->assertSame('2.7500', (string) $lines[$p->id]->unit_cost);
        $this->assertSame('7.0000', (string) $lines[$q->id]->unit_cost);
    }
}
