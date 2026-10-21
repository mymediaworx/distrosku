<?php

namespace Tests\Feature;

use App\Credit\CreditLimit;
use App\Credit\CreditLimitException;
use App\Delivery\VanSaleService;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\SalesOrderResource\Pages\ListSalesOrders;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Livewire\CounterPos;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Portal\PortalService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase171CreditLimitTest extends TestCase
{
    use RefreshDatabase;

    private Customer $ky;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
        $this->admin = User::factory()->create(['role' => 'owner', 'active' => true]);
    }

    private function draft(string $qty, string $sku = 'TST-GEN-001'): SalesOrder
    {
        $p = Product::where('sku', $sku)->first();
        $so = SalesOrder::create(['number' => 'SO-C'.uniqid(), 'customer_id' => $this->ky->id, 'ship_to_id' => $this->ky->shipTos()->value('id'),
            'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);

        return $so->fresh();
    }

    private function limit(string $l): void
    {
        $this->ky->update(['credit_limit' => $l]);
        $this->ky->refresh();
    }

    private function price(): float
    {
        return (float) Product::where('sku', 'TST-GEN-001')->value('price');
    }

    public function test_zero_limit_means_no_limit(): void
    {
        $this->limit('0');
        $so = app(SalesOrderService::class)->confirm($this->draft('100'));
        $this->assertSame('confirmed', $so->status->value);
        $this->assertNull(app(CreditLimit::class)->available($this->ky));
        $this->assertStringStartsWith('No limit', CreditLimit::summary($this->ky));
    }

    public function test_confirm_blocked_over_limit_counts_open_orders_and_unpaid_invoices(): void
    {
        $p = $this->price();
        $svc = app(SalesOrderService::class);
        $this->limit((string) ($p * 10));
        $a = $svc->confirm($this->draft('4'));                 // open confirmed order: 4
        $b = $svc->confirm($this->draft('3'));
        $svc->deliver($b);
        $b = $svc->invoice($b->fresh());                      // unpaid invoice: 3
        $this->assertEqualsWithDelta($p * 7, (float) app(CreditLimit::class)->exposure($this->ky), 0.001);
        $this->assertEqualsWithDelta($p * 3, (float) app(CreditLimit::class)->available($this->ky), 0.001);
        $c = $this->draft('4');
        try {
            $svc->confirm($c);
            $this->fail('expected credit block');
        } catch (CreditLimitException $e) {
            $this->assertStringContainsString('Credit limit exceeded', $e->getMessage());
        }
        $this->assertSame('draft', $c->fresh()->status->value);
        // a payment frees credit
        $svc->recordPayment($b->fresh(), $b->total, 'check', 'CHK1');
        $this->assertSame('confirmed', $svc->confirm($c->fresh())->status->value);   // 4 + 4 = 8
        $this->assertSame('confirmed', $svc->confirm($this->draft('2'))->status->value); // exactly at the limit (10) is allowed
        $this->assertThrows(fn () => $svc->confirm($this->draft('1')), CreditLimitException::class);
    }

    public function test_paid_terms_and_paid_tenders_are_not_blocked(): void
    {
        $this->limit('1');
        $this->ky->update(['terms' => 'COD']);
        $this->assertSame('confirmed', app(SalesOrderService::class)->confirm($this->draft('5'))->status->value);
        $this->ky->update(['terms' => 'Net 15']);
        $u = User::factory()->create(['role' => 'sales', 'active' => true]);
        Livewire::actingAs($u)->test(CounterPos::class)->set('customerId', $this->ky->id)->set('scan', 'TST-GEN-001')->call('add')
            ->set('tender', 'account')->call('checkout')->assertSet('error', fn ($e) => str_contains((string) $e, 'Credit limit exceeded'));
        $this->assertSame(0, SalesOrder::where('channel', 'pos')->count());
        Livewire::actingAs($u)->test(CounterPos::class)->set('customerId', $this->ky->id)->set('scan', 'TST-GEN-001')->call('add')
            ->set('tender', 'cash')->call('checkout')->assertSet('error', null);
        $this->assertSame(1, SalesOrder::where('channel', 'pos')->where('status', 'invoiced')->count());
    }

    public function test_van_account_charge_blocked_cash_allowed(): void
    {
        $this->limit('1');
        $driver = User::where('role', 'driver')->first();
        $van = Warehouse::create(['code' => 'VAN1', 'name' => 'Van 1', 'state' => 'KY', 'is_van' => true, 'van_driver_id' => $driver->id]);
        $p = Product::where('sku', 'TST-GEN-001')->first();
        app(InventoryService::class)->move($p, $van, '10', 'adjustment', null, $p->cost, 'load van');
        $this->actingAs($driver);
        $svc = app(VanSaleService::class);
        $st = $this->ky->shipTos()->first();
        $this->assertThrows(fn () => $svc->sell($van, $this->ky, $st, [['product_id' => $p->id, 'qty' => 2]], 'account'), CreditLimitException::class);
        $this->assertSame(0, SalesOrder::where('channel', 'van')->count());
        $this->assertSame('invoiced', $svc->sell($van, $this->ky, $st, [['product_id' => $p->id, 'qty' => 2]], 'cash')->status->value);
    }

    public function test_portal_checkout_shows_friendly_message_and_saves_nothing(): void
    {
        $this->limit('1');
        $k = CustomerContact::create(['customer_id' => $this->ky->id, 'name' => 'Pat Buyer', 'email' => 'cl@example.test']);
        $k->setPasswordFromLink('Secret12345');
        $p = Product::where('sku', 'TST-GEN-001')->first();
        try {
            app(PortalService::class)->submit($k->fresh(), $this->ky->shipTos()->first(), [$p->id => 2]);
            $this->fail('expected block');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('more than your available credit', $e->getMessage());
            $this->assertStringNotContainsString('owner/admin', $e->getMessage());
        }
        $this->assertSame(0, SalesOrder::where('channel', 'portal')->count());
    }

    public function test_owner_override_with_reason_is_logged_and_sales_cannot_override(): void
    {
        $this->limit('1');
        $so = $this->draft('5');
        $sales = User::factory()->create(['role' => 'sales', 'active' => true]);
        $this->assertThrows(fn () => app(CreditLimit::class)->override($so, $sales, 'please'), WorkflowException::class);
        $this->assertThrows(fn () => app(CreditLimit::class)->override($so, $this->admin, ' '), WorkflowException::class);
        $this->actingAs($this->admin);
        app(CreditLimit::class)->override($so, $this->admin, 'Owner approved: check in the mail');
        $this->assertSame('confirmed', app(SalesOrderService::class)->confirm($so->fresh())->status->value);
        $log = ActivityLog::where('event', 'credit_override')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringContainsString('check in the mail', $log->description);
        // the Filament action does the same in one step
        $so2 = $this->draft('5');
        Livewire::test(ListSalesOrders::class)
            ->callTableAction('creditOverride', $so2, ['reason' => 'Approved by owner'])->assertHasNoTableActionErrors();
        $this->assertSame('confirmed', $so2->fresh()->status->value);
        $this->assertSame('Approved by owner', $so2->fresh()->credit_override_reason);
    }

    public function test_screens_show_available_credit_and_new_user_defaults_active(): void
    {
        $this->limit('500');
        $this->actingAs($this->admin);
        Livewire::test(EditCustomer::class, ['record' => $this->ky->getRouteKey()])->assertSee('Available credit')->assertSee('available of $500.00 limit');
        $this->get('/admin/sales-orders/create')->assertOk();
        Livewire::test(CreateUser::class)->assertFormSet(['active' => true]);
    }
}
