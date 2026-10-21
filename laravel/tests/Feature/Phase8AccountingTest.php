<?php

namespace Tests\Feature;

use App\Accounting\ApService;
use App\Accounting\BankReconService;
use App\Accounting\DunningService;
use App\Accounting\FinancialStatements;
use App\Accounting\PeriodService;
use App\Exceptions\PeriodClosedException;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\Dunning;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\DunningNotice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PeriodAudit;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\Warehouse;
use App\Services\CreditMemoService;
use App\Services\CustomerBalanceService;
use App\Services\PostingService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Phase8AccountingTest extends TestCase
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
    }

    private function bal(string $code): string
    {
        $a = Account::where('code', $code)->first();

        return JournalLine::where('account_id', $a->id)->get()->reduce(fn ($c, $l) => bcadd($c, bcsub($l->debit, $l->credit, 4), 4), '0.0000');
    }

    private function po(Product $p, string $qty, string $cost, ?Vendor $v = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(['number' => 'PO-T'.uniqid(), 'vendor_id' => ($v ?? Vendor::factory()->create())->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_cost' => $cost]);

        return $po->fresh();
    }

    private function invoiced(Product $p, string $qty, ?Customer $c = null): SalesOrder
    {
        $c ??= Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['number' => 'SO-T'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $s->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);
        $svc = app(SalesOrderService::class);
        $so = $svc->confirm($so->fresh());
        $svc->pick($so);
        $svc->deliver($so);

        return $svc->invoice($so)->fresh();
    }

    public function test_three_way_match_bill_and_payment(): void
    {
        $p = Product::factory()->create(['cost' => '1']);
        $po = app(PurchaseOrderService::class)->receive($this->po($p, '10', '4.00'));
        $this->assertSame('-40.0000', $this->bal('2050'));
        $this->assertSame('0.0000', $this->bal('2000'));

        $ap = app(ApService::class);
        $b = $ap->draftFromPurchaseOrder($po, 'INV-1', now()->toDateString());
        $this->assertSame('matched', $b->match_status);
        $this->assertSame('40.0000', $b->total);
        $ap->post($b, $this->admin);
        $this->assertSame('0.0000', $this->bal('2050'));
        $this->assertSame('-40.0000', $this->bal('2000'));
        $this->assertSame('10.0000', $po->lines()->first()->qty_billed);

        $bank = BankAccount::default();
        $pay = $ap->pay($po->vendor, $bank, now()->toDateString(), [$b->id => '25'], 'check', '1001');
        $this->assertSame('partial', $b->fresh()->status);
        $this->assertSame('-15.0000', $this->bal('2000'));
        $this->assertSame('-25.0000', $this->bal('1000'));
        $aging = $ap->aging();
        $this->assertSame('15.0000', $aging['raw_totals']['total']);

        $ap->voidPayment($pay, 'test');
        $this->assertSame('posted', $b->fresh()->status);
        $this->assertSame('-40.0000', $this->bal('2000'));

        $this->expectException(WorkflowException::class);
        $ap->draftFromPurchaseOrder($po->fresh()); // nothing left to bill
    }

    public function test_price_variance_needs_admin_approval_and_posts_ppv(): void
    {
        $p = Product::factory()->create();
        $po = app(PurchaseOrderService::class)->receive($this->po($p, '10', '4.00'));
        $ap = app(ApService::class);
        $b = $ap->draftFromPurchaseOrder($po);
        $b->lines()->first()->update(['unit_cost' => '4.50']);
        $b = $ap->recalc($b);
        $this->assertSame('variance', $b->match_status);
        try {
            $ap->post($b, $this->admin);
            $this->fail('variance posted without approval');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('approve', $e->getMessage());
        }
        try {
            $ap->post($b, User::factory()->create(['role' => 'sales']), true);
            $this->fail('non-admin approved');
        } catch (WorkflowException) {
        }
        $ap->post($b->fresh(), $this->admin, true);
        $this->assertSame('5.0000', $this->bal('5100'));
        $this->assertSame('0.0000', $this->bal('2050'));
        $this->assertSame('-45.0000', $this->bal('2000'));
        $this->assertSame($this->admin->id, $b->fresh()->variance_approved_by);
    }

    public function test_pre_phase8_receipts_clear_ap_not_grni(): void
    {
        $p = Product::factory()->create();
        $po = app(PurchaseOrderService::class)->receive($this->po($p, '10', '2.00'));
        // simulate a receipt posted before phase 8: it credited AP
        app(PostingService::class)->post(now()->toDateString(), 'legacy reclass', [['2050', '20', 0], ['2000', 0, '20']]);
        $po->lines()->update(['qty_received_legacy' => '10']);
        $ap = app(ApService::class);
        $ap->post($ap->draftFromPurchaseOrder($po->fresh()), $this->admin);
        $this->assertSame('-20.0000', $this->bal('2000')); // not doubled
        $this->assertSame('0.0000', $this->bal('2050'));
    }

    public function test_standalone_bill_and_void(): void
    {
        $ap = app(ApService::class);
        $b = $ap->createStandalone(Vendor::factory()->create(), [['account_code' => '6100', 'description' => 'Freight', 'qty' => 1, 'unit_cost' => '75']],
            'F-9', now()->toDateString());
        $this->assertSame('none', $b->match_status);
        $ap->post($b, $this->admin);
        $this->assertSame('75.0000', $this->bal('6100'));
        $ap->void($b->fresh(), 'dup');
        $this->assertSame('0.0000', $this->bal('6100'));
        $this->assertSame('void', $b->fresh()->status);

        $bad = $ap->createStandalone(Vendor::factory()->create(), [['description' => 'no account', 'unit_cost' => '5']], null, now()->toDateString());
        $this->expectException(WorkflowException::class);
        $ap->post($bad, $this->admin);
    }

    public function test_period_close_blocks_posting_and_reopen_is_audited(): void
    {
        $per = app(PeriodService::class);
        $last = now()->subMonthNoOverflow()->format('Y-m');
        $per->close($last, $this->admin, 'month end');
        $this->assertTrue(PeriodService::isClosed(now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString()));
        try {
            app(PostingService::class)->post(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'x', [['6000', '1', 0], ['1000', 0, '1']]);
            $this->fail('posted into closed period');
        } catch (PeriodClosedException) {
        }
        // current month still open
        app(PostingService::class)->post(now()->toDateString(), 'ok', [['6000', '1', 0], ['1000', 0, '1']]);

        try {
            $per->reopen($last, User::factory()->create(['role' => 'sales']), 'x');
            $this->fail('sales reopened');
        } catch (WorkflowException) {
        }
        try {
            $per->reopen($last, $this->admin, ' ');
            $this->fail('no reason');
        } catch (WorkflowException) {
        }
        $per->reopen($last, $this->admin, 'late vendor bill');
        $this->assertFalse(PeriodService::isClosed(now()->subMonthNoOverflow()->startOfMonth()->toDateString()));
        $this->assertSame(['close', 'reopen'], PeriodAudit::orderBy('id')->pluck('action')->all());

        // closing the current month blocks invoices too
        $per->close(now()->format('Y-m'), $this->admin);
        $p = Product::factory()->create(['price' => '5']);
        $this->expectException(PeriodClosedException::class);
        app(PurchaseOrderService::class)->receive($this->po($p, '1', '1'));
    }

    public function test_every_transaction_type_posts_and_statements_balance(): void
    {
        $p = Product::factory()->create(['price' => '10', 'cost' => '1']);
        $po = app(PurchaseOrderService::class)->receive($this->po($p, '20', '4.00'));
        $ap = app(ApService::class);
        $bill = $ap->post($ap->draftFromPurchaseOrder($po), $this->admin);
        $vp = $ap->pay($po->vendor, BankAccount::default(), now()->toDateString(), [$bill->id => '80']);
        $so = $this->invoiced($p, '5');
        $pay = app(SalesOrderService::class)->recordPayment($so, '30', 'check', 'C1');
        $cm = app(CreditMemoService::class);
        $memo = $cm->create($so->fresh(), [['sales_order_line_id' => $so->lines()->first()->id, 'qty' => '1', 'disposition' => 'restock']], 'test');
        $cm->post($memo);
        $c = Customer::factory()->create();
        app(CustomerBalanceService::class)->setBeginningBalance($c, '100', now()->toDateString(), $this->admin);
        $manual = app(PostingService::class)->manual(now()->toDateString(), 'accrual', [['account' => '6000', 'debit' => '12'], ['account' => '2000', 'credit' => '12']], $this->admin);

        foreach ([$po, $bill, $vp, $so, $pay, $memo] as $src) {
            $this->assertTrue(Journal::where('source_type', $src->getMorphClass())->where('source_id', $src->id)->exists(), get_class($src).' did not post');
        }
        $this->assertTrue(Journal::where('source_type', $c->getMorphClass())->where('source_id', $c->id)->exists());
        $this->assertStringContainsString($this->admin->email, $manual->memo);

        $fs = app(FinancialStatements::class);
        $tb = $fs->trialBalance(null, 'prior_period');
        $this->assertTrue($tb['balanced']);
        $this->assertCount(7, $tb['columns']);
        $bs = $fs->balanceSheet();
        $this->assertTrue($bs['balanced'], implode(' ', $bs['notes']));
        $pl = $fs->profitLoss(now()->startOfMonth()->toDateString(), now()->toDateString(), 'prior_year');
        // sales 50 - return 10 = 40; COGS 20 - 4 restock = 16; opex 12 -> 12.0000
        $this->assertSame('12.0000', $pl['net_income']);
        $this->assertCount(5, $pl['columns']);
        $cf = $fs->cashFlow(now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertTrue($cf['reconciles'], implode(' ', $cf['notes']));
        $this->assertSame('-50.00', collect($cf['rows'])->firstWhere(0, 'Net change in cash')[1]); // +30 received -80 paid
        $this->assertStringStartsWith('%PDF', \App\Analytics\ReportService::toPdf($bs));
    }

    public function test_bank_reconciliation_import_match_adjust_reconcile(): void
    {
        $bank = BankAccount::default();
        $p = Product::factory()->create(['price' => '10']);
        app(PurchaseOrderService::class)->receive($this->po($p, '10', '1'));
        $so = $this->invoiced($p, '5');
        app(SalesOrderService::class)->recordPayment($so, '50', 'check', 'DEP77');
        $d = now()->toDateString();
        $csv = "Date,Description,Amount,Reference\n".now()->format('m/d/Y').",Deposit,50.00,DEP77\n".now()->format('m/d/Y').',Service fee,(5.00),';
        $svc = app(BankReconService::class);
        $s = $svc->import($bank, $csv, $d, '0', '45');
        $this->assertSame(1, $s->lines()->where('status', 'matched')->count());
        try {
            $svc->reconcile($s, $this->admin);
            $this->fail('reconciled with unmatched line');
        } catch (WorkflowException) {
        }
        $fee = $s->lines()->where('status', 'unmatched')->first();
        $svc->adjust($fee, '6400', 'bank fee');
        $this->assertSame('5.0000', $this->bal('6400'));
        $sum = $svc->summary($s->fresh());
        $this->assertSame('0.0000', $sum['difference']);
        $svc->reconcile($s->fresh(), $this->admin);
        $this->assertSame('reconciled', $s->fresh()->status);
        $this->assertStringStartsWith('%PDF', \App\Analytics\ReportService::toPdf($svc->report($s->fresh())));

        $parsed = BankReconService::parseCsv("Posted Date,Payee,Withdrawal,Deposit\n2026-10-01,Check 1001,25.00,\n2026-10-02,Dep,,10");
        $this->assertSame(['-25.0000', '10.0000'], array_column($parsed, 'amount'));
    }

    public function test_dunning_creates_drafts_only(): void
    {
        Mail::fake();
        $p = Product::factory()->create(['price' => '10']);
        app(PurchaseOrderService::class)->receive($this->po($p, '10', '1'));
        $c = Customer::factory()->create(['terms' => 'Net 15', 'email' => 'ap@store.test']);
        $so = $this->invoiced($p, '3', $c);
        $so->update(['invoiced_at' => now()->subDays(40)]);
        $svc = app(DunningService::class);
        $b = $svc->run(now()->toDateString(), $this->admin);
        $this->assertSame(1, $b->notice_count);
        $n = DunningNotice::first();
        $this->assertSame(1, $n->level); // 25 days late -> level 1 (default levels 1/30/60)
        $this->assertSame('draft', $n->status);
        $this->assertStringContainsString($so->number, $n->body);
        $this->assertSame(0, $svc->run(now()->toDateString(), $this->admin)->notice_count); // no repeat within 14 days
        $this->assertStringStartsWith('%PDF', $svc->statementsPdf($b));
        $this->assertStringContainsString('X-Unsent: 1', Dunning::eml($n));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(3, DunningService::levelFor(75)['level']);
    }

    public function test_pages_admin_only(): void
    {
        $this->actingAs($this->admin);
        foreach (['/admin/vendor-bills', '/admin/vendor-bills/create', '/admin/vendor-payments', '/admin/bank-accounts', '/admin/accounting/bank-reconciliation',
            '/admin/accounting/ap-aging', '/admin/accounting/trial-balance', '/admin/accounting/profit-loss?compare=prior_period', '/admin/accounting/balance-sheet',
            '/admin/accounting/cash-flow', '/admin/accounting/periods', '/admin/accounting/dunning', '/admin/journals'] as $u) {
            $this->get($u)->assertOk();
        }
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        foreach (['/admin/vendor-bills', '/admin/accounting/trial-balance', '/admin/accounting/periods', '/admin/accounting/dunning', '/admin/accounting/bank-reconciliation'] as $u) {
            $this->get($u)->assertForbidden();
        }
    }

    public function test_ui_actions_create_bill_pay_and_manual_entry(): void
    {
        $this->actingAs($this->admin);
        $p = Product::factory()->create();
        $po = app(PurchaseOrderService::class)->receive($this->po($p, '4', '5.00'));
        \Livewire\Livewire::test(\App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders::class)
            ->callTableAction('bill', $po, ['vendor_invoice' => 'UI-1', 'bill_date' => now()->toDateString(), 'terms_days' => 30])->assertHasNoTableActionErrors();
        $b = VendorBill::firstOrFail();
        \Livewire\Livewire::test(\App\Filament\Resources\VendorBillResource\Pages\ViewVendorBill::class, ['record' => $b->id])->callAction('post');
        $this->assertSame('posted', $b->fresh()->status);
        \Livewire\Livewire::test(\App\Filament\Resources\VendorPaymentResource\Pages\ListVendorPayments::class)
            ->mountAction('pay')->setActionData(['vendor_id' => $po->vendor_id])
            ->setActionData(['vendor_id' => $po->vendor_id, 'bank_account_id' => BankAccount::default()->id, 'paid_on' => now()->toDateString(), 'method' => 'check', 'alloc' => [$b->id => '20']])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('paid', $b->fresh()->status);
        $undo = \Filament\Forms\Components\Repeater::fake();
        \Livewire\Livewire::test(\App\Filament\Resources\JournalResource\Pages\ListJournals::class)
            ->callAction('manual', ['date' => now()->toDateString(), 'memo' => 'ui', 'lines' => [['account' => '6000', 'debit' => '3'], ['account' => '1000', 'credit' => '3']]])
            ->assertHasNoActionErrors();
        $undo();
        $this->assertSame('3.0000', $this->bal('6000'));
        $this->get('/admin/vendor-bills/'.$b->id)->assertOk()->assertSee('UI-1');
    }
}
