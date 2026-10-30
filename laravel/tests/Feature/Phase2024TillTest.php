<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Livewire\PosTill;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\SalesOrder;
use App\Models\TillSession;
use App\Models\User;
use App\Till\TillService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** #44 (0.20.24): till open -> cash sale -> paid in/out/drop -> close with count, variance posted, Z-report. */
class Phase2024TillTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->cashier = User::factory()->create(['role' => 'sales', 'name' => 'Casey Cashier']);
        $this->actingAs($this->cashier);
    }

    private function sell(string $tender = 'cash'): SalesOrder
    {
        Livewire::test(CounterPos::class)->set('customerId', Customer::where('code', 'CTEST-KY')->value('id'))
            ->set('scan', 'TST-GEN-001')->call('add')->set('tender', $tender)->call('checkout')->assertSet('cart', []);

        return SalesOrder::where('channel', 'pos')->latest('id')->first();
    }

    private function gl(string $code): float
    {
        return (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', $code))->sum('debit')
            - (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', $code))->sum('credit');
    }

    public function test_full_shift_with_a_short_drawer(): void
    {
        Livewire::test(PosTill::class)->set('float', '150')->call('open')->assertSet('error', null)->assertSee('Till open');
        $till = TillSession::firstOrFail();

        $so = $this->sell();                                                  // TST-GEN-001 = $25 cash
        $this->assertSame($till->id, $so->till_session_id);
        $this->sell('check');                                                 // not cash: not in the drawer

        $svc = app(TillService::class);
        $svc->move($till, 'paid_in', '20', 'Change from safe', $this->cashier);
        $svc->move($till, 'paid_out', '12.50', 'Ice for cooler', $this->cashier);
        $svc->move($till, 'drop', '100', 'Bag 17', $this->cashier);
        $this->assertSame('82.50', number_format((float) TillService::expected($till), 2, '.', '')); // 150 + 25 + 20 - 12.50 - 100

        $this->assertEqualsWithDelta(12.50, $this->gl('6500'), 0.001);        // paid-out is an expense

        Livewire::test(PosTill::class)->set('count.20', '4')->set('count.coins', '0.50')   // $80.50 counted
            ->call('close')->assertSet('error', null)->assertSet('message', 'Till closed.');
        $till->refresh();
        $this->assertSame(['closed', '82.50', '80.50', '-2.00'], [$till->status, (string) $till->expected_cash, (string) $till->counted_cash, (string) $till->variance]);
        $this->assertEqualsWithDelta(2.00, $this->gl('6550'), 0.001);          // short: Dr Over/Short
        $this->assertSame(['20' => '4', 'coins' => '0.50'], $till->count_detail);

        $html = $this->get(route('pos.till.z', $till))->assertOk()->getContent();
        $this->assertStringContainsString('Z-report', $html);
        $this->assertStringContainsString('Short', $html);
        $this->assertStringContainsString('Ice for cooler', $html);
        $this->assertDatabaseHas('activity_logs', ['event' => 'till_closed']);
    }

    public function test_over_posts_the_other_way_and_balanced_posts_nothing(): void
    {
        $svc = app(TillService::class);
        $t1 = $svc->open('reg-A', 1, '100', $this->cashier);
        $svc->close($t1, '101', $this->cashier);
        $this->assertEqualsWithDelta(-1.00, $this->gl('6550'), 0.001);        // over: Cr Over/Short
        $t2 = $svc->open('reg-A', 1, '100', $this->cashier);
        $this->assertNull($svc->close($t2, '100', $this->cashier)->journal_id);
    }

    public function test_one_open_till_per_register_and_bad_input_is_refused(): void
    {
        $svc = app(TillService::class);
        $t = $svc->open('reg-B', 1, '50', $this->cashier);
        foreach ([fn () => $svc->open('reg-B', 1, '50', $this->cashier), fn () => $svc->move($t, 'paid_out', '0', 'x', $this->cashier),
            fn () => $svc->move($t, 'paid_out', '5', ' ', $this->cashier), fn () => $svc->move($t, 'bribe', '5', 'x', $this->cashier)] as $bad) {
            try {
                $bad();
                $this->fail('accepted bad till input');
            } catch (\App\Exceptions\WorkflowException) {
            }
        }
        $svc->close($t, '50', $this->cashier);
        $this->expectException(\App\Exceptions\WorkflowException::class);
        $svc->move($t->fresh(), 'drop', '5', 'late', $this->cashier);
    }

    public function test_sales_without_an_open_till_still_work(): void
    {
        $so = $this->sell();
        $this->assertNull($so->till_session_id);
        $this->assertSame('invoiced', $so->status->value);
    }

    public function test_pos_shows_the_till_link(): void
    {
        $this->get(route('pos'))->assertOk()->assertSee('Till not open', false)->assertSee(route('pos.till'), false);
    }
}
