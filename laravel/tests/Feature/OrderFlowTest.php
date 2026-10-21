<?php

namespace Tests\Feature;

use App\Enums\SalesOrderStatus;
use App\Exceptions\ComplianceException;
use App\Exceptions\InventoryException;
use App\Exceptions\WorkflowException;
use App\Models\Account;
use App\Models\Buydown;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\Inventory;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = Warehouse::where('code', 'MAIN')->first();
    }

    private function balance(string $code): string
    {
        $a = Account::where('code', $code)->first();
        $d = JournalLine::where('account_id', $a->id)->get()->reduce(fn ($c, $l) => bcadd($c, bcsub($l->debit, $l->credit, 4), 4), '0');

        return $d;
    }

    private function receive(Product $p, string $qty, string $cost): PurchaseOrder
    {
        $po = PurchaseOrder::create(['number' => 'PO-T'.uniqid(), 'vendor_id' => Vendor::factory()->create()->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_cost' => $cost]);

        return app(PurchaseOrderService::class)->receive($po);
    }

    private function order(Product $p, string $qty, string $state = 'KY', ?Customer $c = null): SalesOrder
    {
        $c ??= Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => $state]);
        $so = SalesOrder::create(['number' => 'SO-T'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $s->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);

        return $so->fresh();
    }

    public function test_purchase_receipt_increases_stock_and_posts(): void
    {
        $p = Product::factory()->create(['cost' => '1']);
        $po = $this->receive($p, '10', '4.25');

        $this->assertSame('received', $po->status);
        $this->assertSame('10.0000', Inventory::where('product_id', $p->id)->first()->qty);
        $this->assertSame('42.5000', $this->balance('1200'));
        $this->assertSame('-42.5000', $this->balance('2050')); // phase 8: Received Not Billed until the vendor bill
        $this->assertSame('4.2500', $p->fresh()->cost);

        $this->expectException(WorkflowException::class);
        app(PurchaseOrderService::class)->receive($po->fresh());
    }

    public function test_full_sales_flow_reserves_decrements_posts_and_pays(): void
    {
        $p = Product::factory()->create(['price' => '8.00']);
        $this->receive($p, '10', '5.00');
        $svc = app(SalesOrderService::class);
        Buydown::create(['manufacturer' => 'TEST', 'product_id' => $p->id, 'description' => 't', 'amount_per_unit' => '0.50', 'starts_on' => now()->subDay()]);

        $so = $svc->confirm($this->order($p, '3'));
        $inv = Inventory::where('product_id', $p->id)->first();
        $this->assertSame(SalesOrderStatus::Confirmed, $so->status);
        $this->assertSame('3.0000', $inv->reserved);
        $this->assertSame('10.0000', $inv->qty);

        $svc->pick($so);
        $svc->deliver($so);
        $inv->refresh();
        $this->assertSame('7.0000', $inv->qty);
        $this->assertSame('0.0000', $inv->reserved);

        $svc->invoice($so);
        $this->assertSame(SalesOrderStatus::Invoiced, $so->fresh()->status);
        $this->assertSame('24.0000', $this->balance('1100'));
        $this->assertSame('-24.0000', $this->balance('4000'));
        $this->assertSame('15.0000', $this->balance('5000'));
        $this->assertSame('35.0000', $this->balance('1200')); // 50 - 15
        $this->assertSame('3.0000', Buydown::first()->units_sold);

        $svc->recordPayment($so->fresh(), '24.00', 'check', 'TEST-1001');
        $this->assertSame('0.0000', $this->balance('1100'));
        $this->assertSame('24.0000', $this->balance('1000'));
        $this->assertSame('24.0000', $so->fresh()->amount_paid);

        // Ledger as a whole balances.
        $this->assertSame(0, bccomp((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'), 4));
    }

    public function test_confirm_blocked_by_compliance_and_reasons_saved(): void
    {
        $p = Product::factory()->create(['tax_class' => 'cigarette_stamped', 'pact_covered' => true]);
        $this->receive($p, '5', '50');
        $so = $this->order($p, '1', 'IL');

        try {
            app(SalesOrderService::class)->confirm($so);
            $this->fail('expected compliance block');
        } catch (ComplianceException $e) {
            $this->assertArrayHasKey($p->sku, $e->reasons);
        }
        $so->refresh();
        $this->assertSame(SalesOrderStatus::Draft, $so->status);
        $this->assertNotEmpty($so->compliance_reasons);
        $this->assertSame('0.0000', Inventory::where('product_id', $p->id)->first()->reserved);
    }

    public function test_ky_cigarette_needs_license_from_seeded_rules(): void
    {
        $p = Product::factory()->create(['tax_class' => 'cigarette_stamped', 'pact_covered' => true]);
        $this->receive($p, '5', '50');
        $c = Customer::factory()->create();
        $svc = app(SalesOrderService::class);

        $this->expectException(ComplianceException::class);
        try {
            $svc->confirm($this->order($p, '1', 'KY', $c));
        } finally {
            CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'type' => 'tobacco_retail']);
            $this->assertSame(SalesOrderStatus::Confirmed, $svc->confirm($this->order($p, '1', 'KY', $c))->status);
        }
    }

    public function test_cannot_oversell(): void
    {
        $p = Product::factory()->create();
        $this->receive($p, '2', '1');
        $this->expectException(InventoryException::class);
        app(SalesOrderService::class)->confirm($this->order($p, '3'));
    }

    public function test_status_flow_enforced_and_cancel_releases(): void
    {
        $p = Product::factory()->create();
        $this->receive($p, '5', '1');
        $svc = app(SalesOrderService::class);
        $so = $this->order($p, '2');

        try {
            $svc->invoice($so);
            $this->fail('draft cannot be invoiced');
        } catch (WorkflowException) {
        }
        $svc->confirm($so);
        $svc->cancel($so);
        $this->assertSame('0.0000', Inventory::where('product_id', $p->id)->first()->reserved);
        $this->assertSame(SalesOrderStatus::Cancelled, $so->fresh()->status);
    }
}
