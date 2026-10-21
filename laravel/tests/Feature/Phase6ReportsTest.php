<?php

namespace Tests\Feature;

use App\Analytics\KpiService;
use App\Analytics\ReportService;
use App\Filament\Pages\Reports;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\CreditMemoService;
use App\Services\CustomerBalanceService;
use App\Services\PostingService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase6ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c1;

    private Customer $c2;

    private Product $pA;

    private Product $pB;

    private Product $pArch;

    private Department $dA;

    private User $rep;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $wh = Warehouse::where('code', 'MAIN')->first();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->rep = User::factory()->create(['role' => 'sales', 'name' => 'Rep One']);
        $this->dA = Department::create(['code' => 'ELEC', 'name' => 'Electronics']);
        $dB = Department::create(['code' => 'VAPE', 'name' => 'Vape']);
        $this->pA = Product::factory()->create(['cost' => '5', 'price' => '8', 'department_id' => $this->dA->id, 'reorder_point' => '100']);
        $this->pB = Product::factory()->create(['cost' => '2', 'price' => '3', 'department_id' => $dB->id]);
        $this->pArch = Product::factory()->create(['cost' => '5', 'price' => '8', 'department_id' => $this->dA->id]);
        foreach ([[$this->pA, '5'], [$this->pB, '2'], [$this->pArch, '5']] as [$p, $cost]) {
            $po = PurchaseOrder::create(['number' => 'PO-'.uniqid(), 'vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $wh->id, 'order_date' => now()]);
            $po->lines()->create(['product_id' => $p->id, 'qty' => '100', 'unit_cost' => $cost]);
            app(PurchaseOrderService::class)->receive($po);
        }
        $this->c1 = Customer::factory()->create(['name' => 'Alpha Mart']);
        $this->c1->forceFill(['sales_rep_id' => $this->rep->id])->save();
        $this->c2 = Customer::factory()->create(['name' => 'Beta Shop']);

        $so1 = $this->invoice($this->c1, $wh, [[$this->pA, '3'], [$this->pB, '10']]); // 24 + 30 = 54, cost 35
        $this->invoice($this->c2, $wh, [[$this->pArch, '2']]);                       // 16, cost 10
        $this->pArch->archive($this->admin);

        $cms = app(CreditMemoService::class);
        $cms->post($cms->create($so1->fresh(), [['sales_order_line_id' => $so1->lines()->where('product_id', $this->pB->id)->value('id'), 'qty' => '2']])); // -6, cost -4
        app(SalesOrderService::class)->recordPayment($so1->fresh(), '20', 'cash', 'R1');
        $bb = app(CustomerBalanceService::class);
        $bb->setBeginningBalance($this->c2, '100', now()->startOfMonth()->toDateString(), $this->admin);
        $bb->payOpening($this->c2->fresh(), '30', 'check', 'CHK9');
    }

    private function invoice(Customer $c, Warehouse $wh, array $lines): SalesOrder
    {
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $wh->id, 'order_date' => now()->toDateString()]);
        foreach ($lines as [$p, $q]) {
            $so->lines()->create(['product_id' => $p->id, 'qty' => $q, 'unit_price' => $p->price]);
        }
        $svc = app(SalesOrderService::class);
        $so = $svc->confirm($so->fresh());
        $svc->pick($so);
        $svc->deliver($so);

        return $svc->invoice($so);
    }

    private function svc(): ReportService
    {
        return app(ReportService::class);
    }

    private function col(array $r, string $label, string $col): ?string
    {
        $i = array_search($col, $r['columns'], true);
        foreach ($r['rows'] as $row) {
            if (str_contains($row[0], $label)) {
                return $row[$i];
            }
        }

        return null;
    }

    public function test_order_inherits_customer_rep(): void
    {
        $this->assertSame($this->rep->id, SalesOrder::where('customer_id', $this->c1->id)->value('sales_rep_id'));
    }

    public function test_sales_totals_by_every_dimension(): void
    {
        foreach (['customer', 'product', 'department', 'rep'] as $dim) {
            $r = $this->svc()->salesBy($dim);
            $this->assertSame(['Total', '13', '70.00', '6.00', '64.00', '41.00', '23.00', '35.9%'], $r['totals'], $dim);
        }
        $c = $this->svc()->salesBy('customer');
        $this->assertSame('48.00', $this->col($c, 'Alpha Mart', 'Net sales'));
        $this->assertSame('16.00', $this->col($c, 'Beta Shop', 'Net sales'));
        $d = $this->svc()->salesBy('department');
        $this->assertSame('40.00', $this->col($d, 'Electronics', 'Net sales'));
        $this->assertSame('25.00', $this->col($d, 'Electronics', 'Cost'));
        $this->assertSame('24.00', $this->col($d, 'Vape', 'Net sales'));
        $rep = $this->svc()->salesBy('rep');
        $this->assertSame('48.00', $this->col($rep, 'Rep One', 'Net sales'));
        $this->assertSame('16.00', $this->col($rep, '(no rep)', 'Net sales'));
        // Archived product stays in history.
        $this->assertSame('16.00', $this->col($this->svc()->salesBy('product'), '(archived)', 'Net sales'));
    }

    public function test_filters_and_date_range(): void
    {
        $r = $this->svc()->salesBy('product', null, null, ['customer_id' => $this->c1->id, 'department_id' => $this->dA->id]);
        $this->assertSame('24.00', $r['totals'][4]);
        $this->assertCount(1, $r['rows']);
        $old = $this->svc()->salesBy('customer', '2020-01-01', '2020-01-31');
        $this->assertSame('0.00', $old['totals'][4]);
        $gm = $this->svc()->grossMargin(null, null, ['group' => 'department']);
        $this->assertSame('15.00', $this->col($gm, 'Electronics', 'Gross margin'));
    }

    public function test_ar_aging_and_statement_include_beginning_balance(): void
    {
        $a = $this->svc()->arAging();
        $this->assertSame('114.0000', $a['raw_totals']['total']);
        $this->assertSame('86.00', $this->col($a, 'Beta Shop', 'Total'));
        $this->assertSame('28.00', $this->col($a, 'Alpha Mart', 'Total'));

        foreach ([[$this->c1, '28.0000'], [$this->c2, '86.0000']] as [$c, $bal]) {
            $s = $this->svc()->statement($c->id, '2000-01-01', now()->toDateString());
            $this->assertSame($bal, $s['closing']);
            $this->assertSame($bal, $c->fresh()->balance(), 'statement matches CustomerBalanceService');
        }
        // Opening balance carries everything before the range.
        $s = $this->svc()->statement($this->c2->id, now()->addDay()->toDateString(), now()->addDays(5)->toDateString());
        $this->assertSame('86.0000', $s['opening']);
    }

    public function test_inventory_payments_and_tax(): void
    {
        $v = $this->svc()->inventoryValuation();
        $this->assertSame('1159.0000', $v['raw_total']); // 97*5 + 92*2 + 98*5
        $this->assertSame('485.00', $this->svc()->inventoryValuation(['product_id' => $this->pA->id])['totals'][6]);
        $m = $this->svc()->inventoryMovement();
        $this->assertSame('302', $m['totals'][count($m['columns']) - 3]);
        $this->assertSame('15', $m['totals'][count($m['columns']) - 2]);

        $p = $this->svc()->paymentsReceived();
        $this->assertSame('50.0000', $p['raw_total']);
        $this->assertSame('20.0000', $this->svc()->paymentsReceived(null, null, ['customer_id' => $this->c1->id])['raw_total']);

        app(PostingService::class)->post(now()->toDateString(), 'test excise', [['5000', '12.5', 0], [PostingService::KY_EXCISE_PAYABLE, 0, '12.5']]);
        $t = $this->svc()->taxSummary();
        $this->assertSame('12.5000', $t['raw']['accrued']);
        $this->assertSame('12.5000', app(KpiService::class)->exciseMtd());
    }

    public function test_kpis(): void
    {
        $k = app(KpiService::class);
        $this->assertSame('64.0000', $k->sales()['mtd']);
        $this->assertSame('64.0000', $k->sales()['today']);
        $this->assertSame(0, $k->openOrders()['count']);
        $this->assertSame('114.0000', $k->ar()['total']);
        $this->assertSame(1, $k->lowStock()['count']);
        $this->assertSame('Alpha Mart', substr($k->top('customer')[0][0], -10));
        $this->assertSame(30, count($k->trend(30)['values']));
        $this->assertEquals(64.0, array_sum($k->trend(90)['values']));
    }

    public function test_pages_render_export_and_role_gating(): void
    {
        $pages = [Reports\SalesByCustomer::class, Reports\SalesByProduct::class, Reports\SalesByDepartment::class, Reports\SalesByRep::class,
            Reports\GrossMargin::class, Reports\ArAging::class, Reports\CustomerStatement::class, Reports\InventoryValuation::class,
            Reports\InventoryMovement::class, Reports\TaxSummary::class, Reports\PaymentsReceived::class];
        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk()->assertSee('Month-to-date sales');
        foreach ($pages as $pg) {
            $this->get($pg::getUrl())->assertOk();
        }
        $this->get(Reports\CustomerStatement::getUrl().'?customer_id='.$this->c2->id.'&from=2000-01-01')->assertOk()->assertSee('86.00');
        $this->get(Reports\TaxSummary::getUrl())->assertSee('KY Tobacco Distributor report');
        Livewire::test(Reports\SalesByCustomer::class)->callAction('csv')->assertFileDownloaded();
        Livewire::test(Reports\ArAging::class)->callAction('pdf')->assertFileDownloaded();
        $this->assertStringContainsString('Alpha Mart', ReportService::toCsv($this->svc()->salesBy('customer')));
        $this->assertStringStartsWith('%PDF', ReportService::toPdf($this->svc()->salesBy('customer')));
        // Product picker hides archived products.
        $opts = (new Reports\SalesByProduct)->productOptions();
        $this->assertArrayNotHasKey($this->pArch->id, $opts);
        $this->assertArrayHasKey($this->pA->id, $opts);

        $this->actingAs($this->rep);
        $this->get(Reports\SalesByCustomer::getUrl())->assertOk();
        $this->get(Reports\ArAging::getUrl())->assertForbidden();
        $this->get(Reports\InventoryValuation::getUrl())->assertForbidden();
        $this->get('/admin')->assertOk()->assertSee('AR total')->assertDontSee('KY excise accrued');

        $wh = User::factory()->create(['role' => 'warehouse']);
        $this->actingAs($wh);
        $this->get(Reports\InventoryValuation::getUrl())->assertOk();
        $this->get(Reports\SalesByCustomer::getUrl())->assertForbidden();
        $this->get('/admin')->assertOk()->assertSee('Low stock')->assertDontSee('AR total');
    }
}
