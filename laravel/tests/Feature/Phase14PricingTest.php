<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Models\Buydown;
use App\Models\BuydownAccrual;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Department;
use App\Models\PriceOverrideLog;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SalesOrder;
use App\Models\User;
use App\Portal\PortalPricing;
use App\Pricing\PricingEngine;
use App\Pricing\RebateClaimService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase14PricingTest extends TestCase
{
    use RefreshDatabase;

    private Product $p;

    private Customer $c;

    private PricingEngine $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->p = Product::where('sku', 'TST-GEN-001')->first();
        $this->p->update(['price' => '10']);
        $this->c = Customer::where('code', '!=', 'CTEST-IN')->first();
        $this->e = app(PricingEngine::class);
    }

    public function test_list_dated_qty_break_group_and_contract_precedence(): void
    {
        $this->assertSame(['10.0000', 'list'], [$this->e->quote($this->p, $this->c)->price, $this->e->quote($this->p, $this->c)->source]);

        PriceRule::create(['product_id' => $this->p->id, 'price' => '9', 'starts_on' => now()->subDay(), 'ends_on' => now()->addDay()]);
        PriceRule::create(['product_id' => $this->p->id, 'price' => '8', 'starts_on' => now()->addDays(5)]); // future: ignored
        PriceRule::create(['product_id' => $this->p->id, 'price' => '7', 'ends_on' => now()->subDays(2)]);   // expired: ignored
        $q = $this->e->quote($this->p, $this->c);
        $this->assertSame(['9.0000', 'dated'], [$q->price, $q->source]);

        PriceRule::create(['product_id' => $this->p->id, 'price' => '8.50', 'min_qty' => 10]);
        $this->assertSame('9.0000', $this->e->quote($this->p, $this->c, 9)->price);
        $this->assertSame(['8.5000', 'qty_break'], [$this->e->quote($this->p, $this->c, 10)->price, $this->e->quote($this->p, $this->c, 10)->source]);

        $g = CustomerGroup::create(['code' => 'CHAIN', 'name' => 'Chains']);
        $this->c->update(['customer_group_id' => $g->id]);
        PriceRule::create(['product_id' => $this->p->id, 'customer_group_id' => $g->id, 'price' => '8.75']);
        // 0.19.1: lowest available price wins (qty break 8.50 beats group 8.75 at qty 50; group wins at qty 1)
        $this->assertSame(['8.5000', 'qty_break'], [$this->e->quote($this->p, $this->c->fresh(), 50)->price, $this->e->quote($this->p, $this->c->fresh(), 50)->source]);
        $this->assertSame(['8.7500', 'group'], [$this->e->quote($this->p, $this->c->fresh(), 1)->price, $this->e->quote($this->p, $this->c->fresh(), 1)->source]);

        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '8.90']);
        $q = $this->e->quote($this->p, $this->c->fresh(), 1);
        $this->assertSame(['8.7500', 'group'], [$q->price, $q->source]); // a higher contract no longer beats a lower group price
        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '8.75']);
        $this->assertSame('contract', $this->e->quote($this->p, $this->c->fresh(), 1)->source); // tie: contract preferred
        $other = Customer::factory()->create();
        $this->assertSame('8.5000', $this->e->quote($this->p, $other, 50)->price);
    }

    public function test_promotions_by_product_department_customer(): void
    {
        $dept = Department::create(['code' => 'PRM', 'name' => 'Promo dept']);
        $this->p->update(['department_id' => $dept->id]);
        Promotion::create(['name' => '10% dept', 'kind' => 'percent', 'value' => 10, 'department_id' => $dept->id, 'starts_on' => now()->subDay(), 'ends_on' => now()->addDay()]);
        $q = $this->e->quote($this->p->fresh(), $this->c);
        $this->assertSame(['9.0000', 'list+promo'], [$q->price, $q->source]);

        Promotion::create(['name' => '$1.50 off for one customer', 'kind' => 'amount', 'value' => '1.5', 'customer_id' => $this->c->id, 'product_id' => $this->p->id, 'starts_on' => now()->subDay(), 'ends_on' => now()->addDay()]);
        $this->assertSame('8.5000', $this->e->quote($this->p->fresh(), $this->c)->price);
        $this->assertSame('9.0000', $this->e->quote($this->p->fresh(), Customer::factory()->create())->price);
        // expired promo ignored
        Promotion::create(['name' => 'old', 'kind' => 'percent', 'value' => 90, 'starts_on' => now()->subDays(9), 'ends_on' => now()->subDays(2)]);
        $this->assertSame('8.5000', $this->e->quote($this->p->fresh(), $this->c)->price);
        // 14.1: promotions stack on the contract price (best base first, then the best promo on it)
        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '8']);
        $this->assertSame(['6.5000', 'contract+promo'], [$this->e->quote($this->p->fresh(), $this->c)->price, $this->e->quote($this->p->fresh(), $this->c)->source]);
        // and on a dated price for other customers: 9 - 10% = 8.10
        PriceRule::create(['product_id' => $this->p->id, 'price' => '9', 'starts_on' => now()->subDay()]);
        $this->assertSame(['8.1000', 'dated+promo'], [$this->e->quote($this->p->fresh(), Customer::factory()->create())->price, $this->e->quote($this->p->fresh(), Customer::factory()->create())->source]);
    }

    public function test_order_lines_get_engine_price_source_and_overrides_are_logged(): void
    {
        $this->actingAs($u = User::factory()->create(['role' => 'admin']));
        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '8']);
        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '7', 'min_qty' => 20]);
        $so = SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $this->c->shipTos()->value('id'), 'warehouse_id' => \App\Models\Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $l = $so->lines()->create(['product_id' => $this->p->id, 'qty' => 2, 'unit_price' => null]);
        $this->assertSame(['8.0000', 'contract', '10.0000'], [$l->unit_price, $l->price_source, $l->list_price]);
        $l->update(['qty' => 25]); // follows the qty break
        $this->assertSame('7.0000', $l->fresh()->unit_price);

        $m = $so->lines()->create(['product_id' => $this->p->id, 'qty' => 1, 'unit_price' => '6.25']);
        $this->assertSame(['manual', true, '8.0000'], [$m->price_source, $m->price_overridden, $m->quoted_price]);
        $log = PriceOverrideLog::first();
        $this->assertSame(['6.2500', '8.0000', 'contract', $u->id], [$log->new_price, $log->quoted_price, $log->quoted_source, $log->user_id]);
        $m->update(['qty' => 3]); // manual price kept
        $this->assertSame('6.2500', $m->fresh()->unit_price);
        $this->assertSame(1, PriceOverrideLog::count());
    }

    public function test_pos_and_portal_use_engine(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        PriceRule::create(['product_id' => $this->p->id, 'price' => '9', 'min_qty' => 2]);
        $lw = Livewire::test(CounterPos::class)->set('scan', 'TST-GEN-001')->call('add');
        $this->assertSame('10.0000', $lw->get('cart')[0]['price']);
        $lw->set('cart.0.qty', '2');
        $this->assertSame(['9.0000', 'qty_break'], [$lw->get('cart')[0]['price'], $lw->get('cart')[0]['source']]);
        $lw->set('cart.0.price', '8.00');
        $this->assertSame('manual', $lw->get('cart')[0]['source']);
        $lw->call('checkout');
        $line = SalesOrder::where('channel', 'pos')->first()?->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(['8.0000', 'manual'], [$line->unit_price, $line->price_source]);
        $this->assertSame('pos', PriceOverrideLog::first()->channel);

        $this->assertSame('9.0000', app(PortalPricing::class)->price($this->p, $this->c, 2));
    }

    public function test_buydowns_accrue_per_sale_with_cap_and_claim_report(): void
    {
        $b = Buydown::create(['manufacturer' => 'Acme', 'kind' => 'buydown', 'product_id' => $this->p->id, 'description' => 'Q4', 'amount_per_unit' => '0.50', 'starts_on' => now()->subDay(), 'max_units' => 5]);
        $r = Buydown::create(['manufacturer' => 'Acme', 'kind' => 'rebate', 'product_id' => $this->p->id, 'description' => 'Rebate', 'amount_per_unit' => '0.10', 'starts_on' => now()->subDay()]);
        app(InventoryService::class)->move($this->p, \App\Models\Warehouse::where('code', 'MAIN')->first(), 100, 'receipt');
        $svc = app(SalesOrderService::class);
        foreach ([4, 4] as $qty) {
            $so = SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $this->c->shipTos()->value('id'), 'warehouse_id' => \App\Models\Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
            $so->lines()->create(['product_id' => $this->p->id, 'qty' => $qty, 'unit_price' => null]);
            $svc->confirm($so->fresh());
            $svc->deliver($so->fresh());
            $svc->invoice($so->fresh());
        }
        $this->assertEquals([4, 1], BuydownAccrual::where('buydown_id', $b->id)->orderBy('id')->pluck('qty')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(5, (float) $b->fresh()->units_sold);
        $this->assertEquals(8, (float) $r->fresh()->units_sold);
        $claims = app(RebateClaimService::class);
        $this->assertEquals(3.3, (float) $claims->query()->sum('amount'));
        $csv = $claims->csv($claims->query(manufacturer: 'Acme'));
        $this->assertStringContainsString('Acme', $csv);
        $this->assertSame(4, $claims->markClaimed($claims->query()->get(), 'CLM-1'));
        $this->assertSame(0, $claims->query()->count());
    }

    public function test_pricing_pages_render(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['/price-rules', '/price-rules/create', '/promotions', '/promotions/create', '/customer-groups', '/price-override-logs', '/rebate-claims', '/buydowns/create', '/sales-orders/create'] as $p) {
            $this->get('/admin'.$p)->assertOk();
        }
        $this->actingAs(User::factory()->create(['role' => 'warehouse']));
        $this->get('/admin/price-rules')->assertForbidden();
    }

    private function gl(string $code): float
    {
        $id = \App\Models\Account::where('code', $code)->value('id');

        return round((float) \App\Models\JournalLine::where('account_id', $id)->sum('debit') - (float) \App\Models\JournalLine::where('account_id', $id)->sum('credit'), 4);
    }

    public function test_rebate_gl_credit_memo_reversal_and_manufacturer_payment(): void
    {
        $b = Buydown::create(['manufacturer' => 'Acme', 'kind' => 'rebate', 'product_id' => $this->p->id, 'description' => 'R', 'amount_per_unit' => '0.50', 'starts_on' => now()->subDay()]);
        $wh = \App\Models\Warehouse::where('code', 'MAIN')->first();
        app(InventoryService::class)->move($this->p, $wh, 100, 'receipt');
        $svc = app(SalesOrderService::class);
        $so = SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $this->c->shipTos()->value('id'), 'warehouse_id' => $wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $this->p->id, 'qty' => 10, 'unit_price' => null]);
        $svc->confirm($so->fresh());
        $svc->deliver($so->fresh());
        $so = $svc->invoice($so->fresh());
        $this->assertEquals(5.0, $this->gl('1250'));
        $this->assertEquals(-5.0, $this->gl('5050'));

        // return 4 of 10: reverse 40% of the accrual
        $cms = app(\App\Services\CreditMemoService::class);
        $line = $so->lines()->first();
        $cm = $cms->create($so->fresh(), [['sales_order_line_id' => $line->id, 'qty' => 4, 'disposition' => 'restock']], 'test', now()->toDateString());
        $cms->post($cm);
        $rev = BuydownAccrual::where('kind', 'reversal')->first();
        $this->assertSame(['-4.0000', '-2.0000', $cm->id], [$rev->qty, $rev->amount, $rev->credit_memo_id]);
        $this->assertEquals(6, (float) $b->fresh()->units_sold);
        $this->assertEquals(3.0, $this->gl('1250'));
        $this->assertEquals(-3.0, $this->gl('5050'));
        $cms->post($cms->create($so->fresh(), [['sales_order_line_id' => $line->id, 'qty' => 6, 'disposition' => 'restock']], 'rest', now()->toDateString()));
        $this->assertEquals(0.0, $this->gl('1250'));        // fully returned: nothing receivable
        $this->assertEquals(0.0, (float) BuydownAccrual::sum('amount'));

        // a second sale, claimed and paid short
        $so2 = SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $this->c->shipTos()->value('id'), 'warehouse_id' => $wh->id, 'order_date' => now()->toDateString()]);
        $so2->lines()->create(['product_id' => $this->p->id, 'qty' => 8, 'unit_price' => null]);
        $svc->confirm($so2->fresh());
        $svc->deliver($so2->fresh());
        $svc->invoice($so2->fresh());
        $claims = app(RebateClaimService::class);
        $rows = $claims->query()->get();
        $this->assertEquals(4.0, (float) $rows->sum('amount')); // claim nets the reversals: 5 - 2 - 3 + 4
        $claims->markClaimed($rows, 'CLM-9');
        $ledger = app(\App\Pricing\RebateLedger::class);
        $ledger->recordPayment(BuydownAccrual::all(), now()->toDateString(), '3.50', 'CHK 1');
        $this->assertEquals(0.0, $this->gl('1250'));
        $this->assertEquals(-3.5, $this->gl('5050'));       // earned 4, short paid 0.50 written back
        $this->assertSame(0, BuydownAccrual::whereNull('paid_at')->count());
        $this->expectException(\App\Exceptions\WorkflowException::class);
        $ledger->recordPayment(BuydownAccrual::all(), now()->toDateString(), '1');
    }

    public function test_catch_up_posts_gl_for_accruals_without_journal(): void
    {
        $b = Buydown::create(['manufacturer' => 'Acme', 'product_id' => $this->p->id, 'description' => 'B', 'amount_per_unit' => '1', 'starts_on' => now()->subDay()]);
        $wh = \App\Models\Warehouse::where('code', 'MAIN')->first();
        app(InventoryService::class)->move($this->p, $wh, 10, 'receipt');
        $svc = app(SalesOrderService::class);
        $so = SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $this->c->shipTos()->value('id'), 'warehouse_id' => $wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $this->p->id, 'qty' => 2, 'unit_price' => null]);
        $svc->confirm($so->fresh());
        $svc->deliver($so->fresh());
        $svc->invoice($so->fresh());
        // simulate a 0.14.0 accrual: no journal
        $jid = BuydownAccrual::value('journal_id');
        \App\Models\JournalLine::where('journal_id', $jid)->delete();
        \App\Models\Journal::whereKey($jid)->delete();
        BuydownAccrual::query()->update(['journal_id' => null]);
        $this->assertEquals(0.0, $this->gl('1250'));
        $this->seed(\Database\Seeders\Phase14Seeder::class);
        $this->seed(\Database\Seeders\Phase14Seeder::class); // idempotent
        $this->assertEquals(2.0, $this->gl('1250'));
    }
}
