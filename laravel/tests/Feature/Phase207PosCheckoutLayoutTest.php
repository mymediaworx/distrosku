<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #2: POS checkout stays reachable on tablets (layout only, no sale logic changes). */
class Phase207PosCheckoutLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'sales']));
    }

    public function test_order_details_sit_with_the_cart_and_checkout_stands_alone(): void
    {
        Livewire::test(CounterPos::class)
            ->assertSeeHtmlInOrder(['data-test="order-details"', 'data-test="cart"', 'data-test="checkout"', 'data-test="paybar"'])
            ->assertSeeHtml('id="pos-checkout"');
    }

    public function test_checkout_card_no_longer_contains_customer_or_notes_fields(): void
    {
        $html = Livewire::test(CounterPos::class)->html();
        $checkout = substr($html, strpos($html, 'data-test="checkout"'));
        $checkout = substr($checkout, 0, strpos($checkout, 'data-test="paybar"'));

        $this->assertStringContainsString('Complete sale', $checkout);
        $this->assertStringNotContainsString('pos-customer', $checkout);
        $this->assertStringNotContainsString('pos-notes', $checkout);
    }

    public function test_each_cart_line_shows_its_extended_total_at_two_decimals(): void
    {
        Livewire::test(CounterPos::class)
            ->set('scan', 'TST-GEN-001')->call('add')   // list price 25.0000
            ->call('inc', 0)
            ->assertSet('cart.0.qty', '2')
            ->assertSeeHtml('data-test="line-total">$50.00</span>');
    }

    public function test_pay_bar_shows_item_count_and_total(): void
    {
        Livewire::test(CounterPos::class)
            ->set('scan', 'TST-GEN-001')->call('add')
            ->set('scan', 'TST-PIP-001')->call('add')   // 25.00 + 13.50
            ->assertSeeHtml('2 item(s) · <strong>$38.50</strong>');
    }

    public function test_fields_have_programmatic_labels_and_page_has_a_heading(): void
    {
        Livewire::test(CounterPos::class)
            ->assertSeeHtml('<h1 class="sr-only">Counter POS</h1>')
            ->assertSeeHtml('for="pos-customer"')->assertSeeHtml('id="pos-customer"')
            ->assertSeeHtml('for="pos-fulfillment"')->assertSeeHtml('for="pos-warehouse"')
            ->assertSeeHtml('for="pos-notes"');
    }

    public function test_notes_open_automatically_when_a_note_exists(): void
    {
        Livewire::test(CounterPos::class)
            ->assertSeeHtml('x-data="{ open: false }"')
            ->set('notes', 'Back door')
            ->assertSeeHtml('x-data="{ open: true }"');
    }
}
