<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Livewire\CounterPos;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\SalesOrderService;
use App\Tax\SalesTax;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** #43 (0.20.24): sales tax (off by default) + resale / exemption certificates on the customer's licenses. */
class Phase2024SalesTaxTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c;

    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->c = Customer::where('code', 'CTEST-KY')->firstOrFail();
        $this->c->licenses()->whereIn('type', SalesTax::EXEMPT_TYPES)->delete();
        $this->p = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $this->p->update(['taxes' => null, 'price' => '10']);
    }

    private function on(string $rate = '6'): void
    {
        Setting::put('sales_tax_enabled', '1');
        Setting::put('sales_tax_rate', $rate);
    }

    private function cert(array $over = []): void
    {
        $this->c->licenses()->create(array_replace(['type' => 'resale', 'number' => '51A105-001', 'state' => 'KY', 'expires_at' => null], $over));
    }

    private function order(?Customer $c = null): SalesOrder
    {
        $so = SalesOrder::create(['customer_id' => ($c ?? $this->c)->id, 'warehouse_id' => \App\Models\Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString(), 'fulfillment' => 'pickup']);
        $so->lines()->create(['product_id' => $this->p->id, 'qty' => 3, 'unit_price' => '10']);

        return app(SalesOrderService::class)->recalc($so->fresh());
    }

    public function test_off_by_default_changes_nothing(): void
    {
        $this->assertFalse(SalesTax::enabled());
        $so = $this->order();
        $this->assertSame(['30.0000', '0.0000'], [(string) $so->total, (string) $so->tax_total]);
    }

    public function test_charged_without_a_certificate_and_posted_like_other_invoice_taxes(): void
    {
        $this->on();
        $so = $this->order();
        $this->assertSame(['30.0000', '1.8000', '31.8000'], [(string) $so->subtotal, (string) $so->tax_total, (string) $so->total]);
        $this->assertSame('KY sales tax 6%', $so->lines->first()->tax_detail[0]['name']);
        $this->assertSame('0.6000', $so->lines->first()->tax_detail[0]['per_unit']);
    }

    public function test_sales_tax_is_on_price_plus_the_products_own_taxes(): void
    {
        $this->on();
        $this->p->update(['taxes' => [['name' => 'Excise', 'basis' => 'per_unit', 'amount' => '1.00']]]);
        $so = $this->order();
        // per unit: excise 1.00 + 6% of (10 + 1) = 0.66 -> 1.66 x 3 = 4.98
        $this->assertSame('4.9800', (string) $so->tax_total);
    }

    public function test_valid_certificate_exempts_and_expired_or_numberless_does_not(): void
    {
        $this->on();
        $this->cert(['expires_at' => now()->subDay()->toDateString()]);
        $this->assertSame('expired', SalesTax::exemption($this->c)['status']);
        $this->assertSame('1.8000', (string) $this->order()->tax_total);

        $this->cert(['type' => 'sales_tax_exempt', 'number' => '', 'expires_at' => null]);   // no number, no document: not proof
        $this->assertFalse(SalesTax::exemption($this->c->fresh())['exempt']);

        $this->cert(['number' => '51A105-002', 'expires_at' => now()->addDays(10)->toDateString()]);
        $ex = SalesTax::exemption($this->c->fresh());
        $this->assertSame([true, 'expiring'], [$ex['exempt'], $ex['status']]);
        $this->assertSame('0.0000', (string) $this->order()->tax_total);
    }

    public function test_certificate_for_another_state_does_not_count_and_walk_in_always_pays(): void
    {
        $this->on();
        $this->cert(['state' => 'IN']);
        $this->assertFalse(SalesTax::exemption($this->c)['exempt']);

        $cash = Customer::where('code', 'CASH')->firstOrFail();
        $cash->licenses()->create(['type' => 'resale', 'number' => 'X', 'state' => 'KY']);
        $this->assertFalse(SalesTax::exemption($cash)['exempt']);
    }

    public function test_wholesale_price_group_is_not_charged_and_standard_is(): void
    {
        $this->on();
        $this->c->update(['customer_group_id' => \App\Models\CustomerGroup::system(\App\Models\CustomerGroup::WHOLESALE)->id]);
        $ex = SalesTax::exemption($this->c->fresh());
        $this->assertSame([true, 'Exempt: Wholesale price group.'], [$ex['exempt'], $ex['message']]);
        $this->assertSame('0.0000', (string) $this->order()->tax_total);

        $this->c->update(['customer_group_id' => \App\Models\CustomerGroup::system(\App\Models\CustomerGroup::STANDARD)->id]);
        $this->assertSame('1.8000', (string) $this->order()->tax_total);              // Standard, no certificate: taxed
    }

    public function test_built_in_price_groups_have_fixed_codes(): void
    {
        $m = require database_path('migrations/2026_12_02_000008_phase2024_system_price_groups.php');
        $m->up();
        $m->up();
        $this->assertSame(1, \App\Models\CustomerGroup::where('code', 'WHOLESALE')->count());
        $this->assertSame(1, \App\Models\CustomerGroup::where('code', 'STANDARD')->count());
        $w = \App\Models\CustomerGroup::system('WHOLESALE');
        foreach ([fn () => $w->update(['code' => 'WS']), fn () => $w->delete()] as $bad) {
            try {
                $bad();
                $this->fail('changed a built-in price group');
            } catch (\App\Exceptions\WorkflowException) {
            }
        }
        $this->assertTrue($w->fresh()->exists);
        $w->update(['name' => 'Wholesale']);                                            // name edits are fine
    }

    public function test_posted_invoice_is_not_changed_by_turning_tax_on_later(): void
    {
        $so = $this->order();
        $so->forceFill(['status' => \App\Enums\SalesOrderStatus::Invoiced])->save();
        $this->on();
        $this->assertSame('0.0000', (string) app(SalesOrderService::class)->recalc($so->fresh())->tax_total);
    }

    public function test_pos_total_includes_sales_tax_and_shows_the_status(): void
    {
        $this->on();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lw = Livewire::test(CounterPos::class)->set('customerId', $this->c->id)->set('scan', 'TST-GEN-001')->call('add')
            ->assertSeeHtml('data-test="pos-sales-tax"')->assertSee('No resale or exemption certificate on file');
        $this->assertEqualsWithDelta(10.60, (float) $lw->instance()->total, 0.0001); // 10.00 + 6%
    }

    public function test_settings_tab_turns_it_on_and_is_logged(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Settings::class)->fillForm(['company_name' => 'Vega', 'portal_enabled' => true, 'sales_tax_enabled' => true, 'sales_tax_rate' => '6'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(SalesTax::enabled());
        $this->assertDatabaseHas('activity_logs', ['event' => 'sales_tax_setting']);
    }

    public function test_customers_filter_finds_those_without_a_current_certificate(): void
    {
        $this->cert();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ws = Customer::where('code', '!=', 'CTEST-KY')->where('code', '!=', 'CASH')->firstOrFail();
        $ws->update(['customer_group_id' => \App\Models\CustomerGroup::system(\App\Models\CustomerGroup::WHOLESALE)->id]);
        Livewire::test(ListCustomers::class)->filterTable('tax_cert', 'none')
            ->assertCanNotSeeTableRecords([$this->c, $ws])->assertCanSeeTableRecords([Customer::where('code', 'CASH')->first()]);
    }
}
