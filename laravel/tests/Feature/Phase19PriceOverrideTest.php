<?php

namespace Tests\Feature;

use App\Delivery\VanSaleService;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\CustomerPriceOverrideResource;
use App\Livewire\CounterPos;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPriceOverride as O;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Portal\PortalPricing;
use App\Pricing\PricingEngine;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase19PriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Product $p;

    private Customer $c;

    private Customer $other;

    private PricingEngine $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->p = Product::where('sku', 'TST-GEN-001')->first();
        $this->p->update(['price' => '10']);
        $this->c = Customer::where('code', 'CTEST-KY')->first();
        $this->other = Customer::where('code', 'CTEST-IN')->first();
        $this->e = app(PricingEngine::class);
    }

    private function order(?Customer $c = null): SalesOrder
    {
        $c ??= $this->c;
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $c->shipTos()->value('id'), 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $this->p->id, 'qty' => 1]);

        return $so->fresh('lines');
    }

    public function test_lowest_available_price_wins_with_winning_source(): void
    {
        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '8']);
        Promotion::create(['name' => '$1 off', 'kind' => 'amount', 'value' => '1', 'product_id' => $this->p->id, 'starts_on' => now()->subDay(), 'ends_on' => now()->addDay()]);
        $this->assertSame(['7.0000', 'contract+promo'], [$this->e->quote($this->p, $this->c)->price, $this->e->quote($this->p, $this->c)->source]);
        O::create(['product_id' => $this->p->id, 'customer_id' => null, 'price' => '7.50']); // higher than contract+promo: loses
        $this->assertSame(['7.0000', 'contract+promo'], [$this->e->quote($this->p, $this->c)->price, $this->e->quote($this->p, $this->c)->source]);
        $this->assertSame(['7.5000', 'override_all'], [$this->e->quote($this->p, $this->other)->price, $this->e->quote($this->p, $this->other)->source]); // other: list 10-1=9 vs 7.50
        O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '6.25']);
        $q = $this->e->quote($this->p, $this->c);
        $this->assertSame(['6.2500', 'override'], [$q->price, $q->source]);
        $this->assertSame('Customer special price', $q->label());
        $this->assertCount(4, $this->e->candidates($this->p, $this->c)); // customer special, all special, contract+promo, list+promo
        O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '7.00']); // newer but higher customer special
        $this->assertSame('6.2500', $this->e->quote($this->p, $this->c)->price);
    }

    public function test_one_time_consumed_only_if_it_won(): void
    {
        PriceRule::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '2']);
        $o = O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '3', 'duration' => 'one_time']);
        $so = $this->order();
        $this->assertSame('contract', $so->lines[0]->price_source);
        $this->assertNull($so->lines[0]->price_override_id);
        app(SalesOrderService::class)->confirm($so);
        $this->assertNull($o->fresh()->used_at);
    }

    public function test_date_range_and_inactive(): void
    {
        O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '5', 'duration' => 'range', 'starts_on' => now()->addDays(2), 'ends_on' => now()->addDays(5)]);
        $this->assertSame('list', $this->e->quote($this->p, $this->c)->source);
        $this->assertSame('5.0000', $this->e->quote($this->p, $this->c, 1, now()->addDays(3))->price);
        $this->assertSame('list', $this->e->quote($this->p, $this->c, 1, now()->addDays(6))->source);
        O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '4', 'active' => false]);
        $this->assertSame('list', $this->e->quote($this->p, $this->c)->source);
    }

    public function test_one_time_used_by_first_confirmed_order_and_logged(): void
    {
        $o = O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '3', 'duration' => 'one_time']);
        $a = $this->order();
        $b = $this->order(); // draft also priced at the special price
        $this->assertSame('3.0000', $a->lines[0]->unit_price);
        $this->assertSame($o->id, $a->lines[0]->price_override_id);
        app(SalesOrderService::class)->confirm($a);
        $o->refresh();
        $this->assertNotNull($o->used_at);
        $this->assertSame($a->id, $o->used_sales_order_id);
        $this->assertSame('Used', $o->status());
        $this->assertTrue(ActivityLog::where('event', 'price_override_used')->exists());
        $this->assertSame('list', $this->e->quote($this->p, $this->c)->source); // gone for new quotes
        $this->expectException(WorkflowException::class);
        app(SalesOrderService::class)->confirm($b);
    }

    public function test_pos_van_portal_use_override(): void
    {
        O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '4.40']);
        $this->assertSame('4.4000', app(PortalPricing::class)->price($this->p, $this->c));
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        Livewire::test(CounterPos::class)->set('customerId', $this->c->id)->set('scan', $this->p->sku)->call('add')->call('checkout')->assertSee('complete');
        $pos = SalesOrder::where('channel', 'pos')->latest('id')->first();
        $this->assertSame('4.4000', $pos->lines()->first()->unit_price);
        $this->assertSame('override', $pos->lines()->first()->price_source);
        $van = Warehouse::where('code', 'MAIN')->first();
        $van->update(['is_van' => true]);
        $so = app(VanSaleService::class)->sell($van, $this->c, $this->c->shipTos()->first(), [['product_id' => $this->p->id, 'qty' => 1]], 'cash');
        $this->assertSame('4.4000', $so->lines()->first()->unit_price);
    }

    public function test_manual_line_override_still_log_only(): void
    {
        O::create(['product_id' => $this->p->id, 'customer_id' => $this->c->id, 'price' => '4']);
        $so = $this->order();
        $so->lines[0]->update(['unit_price' => '9']);
        $l = $so->lines[0]->fresh();
        $this->assertTrue((bool) $l->price_overridden);
        $this->assertNull($l->price_override_id);
        $this->assertSame('manual', $l->price_source);
    }

    public function test_only_owner_admin_manage_sales_view(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        $this->assertTrue(CustomerPriceOverrideResource::canViewAny());
        $this->assertFalse(CustomerPriceOverrideResource::canCreate());
        $this->get('/admin/customer-price-overrides')->assertOk();
        $this->get('/admin/customer-price-overrides/create')->assertForbidden();
        try {
            O::create(['product_id' => $this->p->id, 'price' => '1']);
            $this->fail('created');
        } catch (WorkflowException) {
        }
        $this->assertSame(0, O::count());
        $this->assertTrue(ActivityLog::where('event', 'price_override_blocked')->exists());
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->get('/admin/customer-price-overrides/create')->assertOk();
        $o = O::create(['product_id' => $this->p->id, 'price' => '1']);
        $this->assertTrue(ActivityLog::where('subject_type', O::class)->where('subject_id', $o->id)->exists()); // audit trail
        $this->actingAs(User::factory()->create(['role' => 'warehouse', 'active' => true]));
        $this->get('/admin/customer-price-overrides')->assertForbidden();
    }

    public function test_migration_rerun_safe(): void
    {
        (include database_path('migrations/2026_11_11_000001_phase19_overrides_ghl.php'))->up();
        $this->assertSame(6, \App\Models\TransactionalTemplate::count());
        $this->assertSame(0, \App\Models\TransactionalTemplate::where('enabled', true)->count());
    }
}
