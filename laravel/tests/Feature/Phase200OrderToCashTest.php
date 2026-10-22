<?php

namespace Tests\Feature;

use App\Credit\CreditLimitException;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\Receivables;
use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrderResource\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrderResource\Pages\ListSalesOrders;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.0: order -> invoice -> payment in the fewest clicks. */
class Phase200OrderToCashTest extends TestCase
{
    use RefreshDatabase;

    private Customer $ky;

    private User $admin;

    private SalesOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
        $this->admin = User::factory()->create(['role' => 'owner', 'active' => true]);
        $this->actingAs($this->admin);
        $this->svc = app(SalesOrderService::class);
    }

    private function draft(string $qty = '2'): SalesOrder
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $so = SalesOrder::create(['number' => 'SO-Z'.uniqid(), 'customer_id' => $this->ky->id, 'ship_to_id' => $this->ky->shipTos()->value('id'),
            'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => '10']);

        return $this->svc->recalc($so->fresh())->fresh();
    }

    private function confirmed(string $qty = '2'): SalesOrder
    {
        return $this->svc->confirm($this->draft($qty))->fresh();
    }

    /** Item 1: Save & confirm on the new order form; failure keeps a Draft with the reason. */
    public function test_save_and_confirm_on_create(): void
    {
        $undo = Repeater::fake();
        $p = Product::where('sku', 'TST-GEN-001')->first();
        Livewire::test(CreateSalesOrder::class)
            ->set('data.customer_id', $this->ky->id)->set('data.fulfillment', 'pickup')
            ->set('data.lines', [['product_id' => $p->id, 'qty' => 1, 'unit_price' => 10]])
            ->call('createAndConfirm')->assertHasNoFormErrors();
        $this->assertSame(S::Confirmed, SalesOrder::latest('id')->first()->status);

        // plain Save stays a draft
        Livewire::test(CreateSalesOrder::class)
            ->set('data.customer_id', $this->ky->id)->set('data.fulfillment', 'pickup')
            ->set('data.lines', [['product_id' => $p->id, 'qty' => 1, 'unit_price' => 10]])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(S::Draft, SalesOrder::latest('id')->first()->status);

        // credit limit blocks -> saved as Draft, reason shown
        $this->ky->update(['credit_limit' => '1', 'terms' => 'Net 30']);
        Livewire::test(CreateSalesOrder::class)
            ->set('data.customer_id', $this->ky->id)->set('data.fulfillment', 'pickup')
            ->set('data.lines', [['product_id' => $p->id, 'qty' => 5, 'unit_price' => 10]])
            ->call('createAndConfirm')->assertHasNoFormErrors()->assertNotified();
        $undo();
        $this->assertSame(S::Draft, SalesOrder::latest('id')->first()->status);
        [, $reason] = $this->svc->tryConfirm(SalesOrder::latest('id')->first());
        $this->assertStringContainsString('Saved as draft', (string) $reason);
    }

    /** Item 2: deliver always invoices (GL posted) in one transaction; invoice() is idempotent for older callers. */
    public function test_deliver_auto_invoices(): void
    {
        $so = $this->svc->deliver($this->confirmed());
        $this->assertSame(S::Invoiced, $so->fresh()->status);
        $this->assertNotNull($so->fresh()->delivered_at);
        $this->assertNotNull($so->fresh()->invoiced_at);
        $this->assertTrue(Journal::where('source_type', $so->getMorphClass())->where('source_id', $so->id)->exists());
        $this->assertSame(S::Invoiced, $this->svc->invoice($so->fresh())->status); // no double post
        $this->assertSame(1, Journal::where('source_type', $so->getMorphClass())->where('source_id', $so->id)->whereNull('reversal_of_id')->count());
    }

    /** Item 3: Record payment: defaults, partial, check #, legacy Delivered order invoiced first. */
    public function test_record_payment_partial_check_and_legacy_delivered(): void
    {
        $so = $this->svc->deliver($this->confirmed())->fresh();
        try {
            $this->svc->settle($so, 'check', '5');
            $this->fail('check without number');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('check number', $e->getMessage());
        }
        $this->svc->settle($so, 'check', '5', 'CHK 101');
        $this->assertSame('partial', $so->fresh()->paymentState());
        $this->svc->settle($so->fresh(), 'cash'); // defaults to balance
        $this->assertSame('paid', $so->fresh()->paymentState());
        $this->expectException(WorkflowException::class);
        $this->svc->recordPayment($so->fresh(), '1', 'cash'); // over balance
    }

    public function test_legacy_delivered_order_invoiced_by_record_payment(): void
    {
        $so = $this->confirmed();
        $so->forceFill(['status' => S::Delivered, 'delivered_at' => now()])->save(); // stuck like SO000001
        $p = $this->svc->settle($so->fresh(), 'cash', '5');
        $this->assertInstanceOf(Payment::class, $p);
        $this->assertSame(S::Invoiced, $so->fresh()->status);
        $this->assertSame('5.0000', (string) $so->fresh()->amount_paid);
    }

    /** Item 3: on account over the limit needs an owner/admin override with a reason (logged). */
    public function test_on_account_over_limit_requires_admin_override(): void
    {
        $so = $this->svc->deliver($this->confirmed('3'))->fresh(); // $30 invoiced
        $this->ky->update(['credit_limit' => '20']);
        try {
            $this->svc->settle($so, 'on_account');
            $this->fail('should block');
        } catch (CreditLimitException $e) {
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }
        $this->assertSame(0, Payment::where('sales_order_id', $so->id)->count());

        $sales = User::factory()->create(['role' => 'sales', 'active' => true]);
        $this->actingAs($sales);
        $this->expectExceptionObject(new WorkflowException('Only an owner or admin can override a credit limit.'));
        try {
            $this->svc->settle($so->fresh(), 'on_account', null, null, 'customer good for it');
        } finally {
            $this->actingAs($this->admin);
            $this->assertNull($this->svc->settle($so->fresh(), 'on_account', null, null, 'Owner approved'));
            $this->assertSame('Owner approved', $so->fresh()->credit_override_reason);
            $this->assertTrue(ActivityLog::where('event', 'credit_override')->exists());
            $this->assertTrue(ActivityLog::where('event', 'order_on_account')->exists());
            $this->assertSame(0, Payment::where('sales_order_id', $so->id)->count());
        }
    }

    public function test_on_account_within_limit_records_no_payment(): void
    {
        $so = $this->svc->deliver($this->confirmed())->fresh();
        $this->assertNull($this->svc->settle($so, 'on_account'));
        $this->assertSame('unpaid', $so->fresh()->paymentState());
    }

    /** Item 4: COD in one step. */
    public function test_deliver_invoice_and_collect(): void
    {
        $so = $this->confirmed();
        $p = $this->svc->deliverAndCollect($so, 'cash');
        $this->assertSame(S::Invoiced, $so->fresh()->status);
        $this->assertSame('20.0000', (string) $p->amount);
        $this->assertSame('paid', $so->fresh()->paymentState());
        // a failed collect rolls everything back (still confirmed)
        $so2 = $this->confirmed();
        try {
            $this->svc->deliverAndCollect($so2, 'check');
        } catch (WorkflowException) {
        }
        $this->assertSame(S::Confirmed, $so2->fresh()->status);
    }

    /** Item 5: header buttons on the order page + list row actions. */
    public function test_header_and_row_actions(): void
    {
        $so = $this->confirmed();
        Livewire::test(EditSalesOrder::class, ['record' => $so->id])
            ->assertActionVisible('deliver')->assertActionVisible('deliverCollect')->assertActionHidden('pay')
            ->callAction('deliver');
        $this->assertSame(S::Invoiced, $so->fresh()->status);
        Livewire::test(EditSalesOrder::class, ['record' => $so->id])
            ->assertActionVisible('pay')->callAction('pay', ['method' => 'card', 'amount' => '20']);
        $this->assertSame('paid', $so->fresh()->paymentState());

        $so2 = $this->confirmed();
        Livewire::test(ListSalesOrders::class)
            ->callTableAction('deliverCollect', $so2, ['method' => 'check', 'amount' => '20', 'reference' => '555'])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $so2->fresh()->paymentState());
        $this->assertSame('555', Payment::where('sales_order_id', $so2->id)->value('reference'));
    }

    /** Item 6: chips + Unpaid/Partial/Paid filter. */
    public function test_status_chips_and_payment_filter(): void
    {
        $a = $this->svc->deliver($this->confirmed())->fresh();
        $b = $this->svc->deliver($this->confirmed())->fresh();
        $this->svc->settle($b, 'cash', '5');
        $c = $this->svc->deliver($this->confirmed())->fresh();
        $this->svc->settle($c, 'cash');
        $this->assertStringContainsString('Paid', $c->fresh()->stageChips());
        $this->assertStringContainsString('Partial', $b->fresh()->stageChips());
        $this->assertSame([$a->id], SalesOrder::wherePayment('unpaid')->pluck('id')->all());
        $this->assertSame([$b->id], SalesOrder::wherePayment('partial')->pluck('id')->all());
        $this->assertSame([$c->id], SalesOrder::wherePayment('paid')->pluck('id')->all());
        Livewire::test(ListSalesOrders::class)->filterTable('payment', 'partial')
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
    }

    /** Item 7: Receivables page with aging, bulk invoice and Record payment. */
    public function test_receivables_page(): void
    {
        $inv = $this->svc->deliver($this->confirmed())->fresh();
        $inv->forceFill(['invoiced_at' => now()->subDays(65)])->save();
        $legacy = $this->confirmed();
        $legacy->forceFill(['status' => S::Delivered, 'delivered_at' => now()])->save();
        $paid = $this->svc->deliver($this->confirmed())->fresh();
        $this->svc->settle($paid, 'cash');
        $this->assertSame('60', Receivables::bucket($inv->fresh()));

        Livewire::test(Receivables::class)
            ->assertCanSeeTableRecords([$inv, $legacy])->assertCanNotSeeTableRecords([$paid])
            ->filterTable('aging', '60')->assertCanSeeTableRecords([$inv])->assertCanNotSeeTableRecords([$legacy]);
        Livewire::test(Receivables::class)->callTableBulkAction('bulkInvoice', [$legacy]);
        $this->assertSame(S::Invoiced, $legacy->fresh()->status);
        Livewire::test(Receivables::class)->callTableAction('pay', $inv, ['method' => 'cash', 'amount' => '20'])->assertHasNoTableActionErrors();
        $this->assertSame('paid', $inv->fresh()->paymentState());
        $this->get(Receivables::getUrl())->assertOk();
    }

    /** Item 8: Payments > New goes through the service (GL + amount_paid). */
    public function test_payments_new_routes_through_service(): void
    {
        $so = $this->svc->deliver($this->confirmed())->fresh();
        Livewire::test(CreatePayment::class)
            ->fillForm(['sales_order_id' => $so->id, 'method' => 'ach', 'amount' => '12', 'reference' => 'ACH1'])
            ->call('create')->assertHasNoFormErrors();
        $p = Payment::where('sales_order_id', $so->id)->first();
        $this->assertSame('12.0000', (string) $so->fresh()->amount_paid);
        $this->assertTrue(Journal::where('source_type', $p->getMorphClass())->where('source_id', $p->id)->exists());
        Livewire::test(CreatePayment::class)
            ->fillForm(['sales_order_id' => $so->id, 'method' => 'cash', 'amount' => '999'])
            ->call('create')->assertHasFormErrors(['amount']);
        $this->assertSame(1, Payment::where('sales_order_id', $so->id)->count());
    }
}
