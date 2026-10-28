<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #27: printable POS receipt (80 mm roll or letter) through Chrome. */
class Phase207PosReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->cashier = User::factory()->create(['role' => 'sales']);
    }

    private function cashSale(string $tendered = '60'): SalesOrder
    {
        $this->actingAs($this->cashier);
        $lw = Livewire::test(CounterPos::class)
            ->set('customerId', \App\Models\Customer::where('code', 'CASH')->value('id'))
            ->set('scan', 'TST-GEN-001')->call('add')
            ->call('inc', 0)                         // 2 × 25.00
            ->set('tender', 'cash')->set('tendered', $tendered)
            ->call('checkout');
        $so = SalesOrder::where('channel', 'pos')->latest('id')->firstOrFail();
        $lw->assertSet('lastSaleId', $so->id)
            ->assertSet('message', "Sale {$so->number} complete: \$50.00. Change due \$10.00.")
            ->assertSeeHtml('data-test="print-receipt"')
            ->assertDispatched('pos-sale-complete', url: route('pos.receipt', ['so' => $so->id, 'tendered' => $tendered, 'print' => 1]));

        return $so;
    }

    public function test_cash_sale_receipt_shows_lines_totals_payment_and_change(): void
    {
        $so = $this->cashSale();

        $html = $this->get(route('pos.receipt', ['so' => $so, 'tendered' => '60']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($so->number)->assertSee('TEST Lighter Display 50ct')->assertSee('2 × $25.00')
            ->assertSeeHtml('data-test="receipt-total">$50.00')
            ->assertSeeHtml('data-test="receipt-change">$10.00')
            ->assertSee('Main Lexington')
            ->getContent();

        $this->assertStringContainsString('data-format="80mm"', $html);
        $this->assertStringContainsString("@page { size: 80mm ' + mm + 'mm", $html); // roll height fitted on load
        $this->assertStringNotContainsString('window.print(), 150', $html);       // no auto-print unless asked
    }

    public function test_letter_format_and_auto_print_flag(): void
    {
        $so = $this->cashSale();

        $html = $this->get(route('pos.receipt', ['so' => $so, 'format' => 'letter', 'print' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-format="letter"', $html);
        $this->assertStringContainsString('size: letter', $html);
        $this->assertStringContainsString('window.print(), 150', $html);
    }

    public function test_change_is_ignored_when_tendered_is_missing_or_too_small(): void
    {
        $so = $this->cashSale();

        $this->get(route('pos.receipt', ['so' => $so]))->assertOk()->assertDontSee('Cash tendered');
        $this->get(route('pos.receipt', ['so' => $so, 'tendered' => '10']))->assertOk()->assertDontSee('Cash tendered');
        $this->get(route('pos.receipt', ['so' => $so, 'tendered' => 'abc']))->assertOk()->assertDontSee('Cash tendered');
    }

    public function test_account_sale_shows_amount_charged_to_account(): void
    {
        $this->actingAs($this->cashier);
        $acct = Customer::where('code', 'CTEST-KY')->firstOrFail();
        Livewire::test(CounterPos::class)
            ->set('customerId', $acct->id)
            ->set('scan', 'TST-GEN-001')->call('add')
            ->set('tender', 'account')
            ->call('checkout')
            ->assertSet('lastTendered', null);
        $so = SalesOrder::where('channel', 'pos')->latest('id')->firstOrFail();

        $this->get(route('pos.receipt', ['so' => $so]))->assertOk()
            ->assertSeeHtml('data-test="receipt-account">$25.00');
    }

    public function test_only_pos_users_and_pos_sales(): void
    {
        $so = $this->cashSale();
        auth()->logout();

        $this->get(route('pos.receipt', ['so' => $so]))->assertRedirect(); // signed out: to login / lock screen
        $this->actingAs(User::factory()->create(['role' => 'driver']))->get(route('pos.receipt', ['so' => $so]))->assertForbidden();

        $so->update(['channel' => 'web']);
        $this->actingAs($this->cashier)->get(route('pos.receipt', ['so' => $so]))->assertNotFound();
    }
}
