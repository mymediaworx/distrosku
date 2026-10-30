<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Warehouse\LotChoice;
use App\Warehouse\LotService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** #36 follow-up (0.20.24): lot picker on the counter POS (and van sales) when a lot-tracked item has 2+ lots on hand. */
class Phase2024PosLotPickerTest extends TestCase
{
    use RefreshDatabase;

    private Product $p;

    private int $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->p = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $this->p->update(['track_lots' => true]);
        $this->main = (int) Warehouse::where('code', 'MAIN')->value('id');
    }

    private function lot(string $n, int $days): int
    {
        return app(LotService::class)->receive($this->p, $this->main, $n, now()->addDays($days)->toDateString(), '10')->id;
    }

    private function customer(): Customer
    {
        return Customer::where('code', 'CTEST-KY')->firstOrFail();
    }

    public function test_no_picker_for_untracked_items_or_a_single_lot(): void
    {
        $this->assertSame(['lots' => [], 'lot_id' => null], LotChoice::forLine($this->p, $this->main)); // no lots yet
        $this->lot('L-ONE', 30);
        $this->assertSame([], LotChoice::forLine($this->p, $this->main)['lots']);                     // one lot: server assigns it
        $this->p->update(['track_lots' => false]);
        $this->assertSame([], LotChoice::forLine($this->p->fresh(), $this->main)['lots']);
    }

    public function test_two_lots_default_to_first_expiring_and_the_chosen_lot_is_sold(): void
    {
        $late = $this->lot('L-LATE', 90);
        $soon = $this->lot('L-SOON', 20);

        $lw = Livewire::test(CounterPos::class)->set('customerId', $this->customer()->id)
            ->set('scan', 'TST-GEN-001')->call('add');
        $this->assertSame($soon, $lw->get('cart.0.lot_id'));                       // FEFO default
        $this->assertSame([$soon, $late], array_keys($lw->get('cart.0.lots')));
        $lw->assertSeeHtml('data-test="line-lot"');

        $lw->set('cart.0.lot_id', $late)->call('checkout')->assertSet('cart', []);
        $line = SalesOrder::where('channel', 'pos')->latest('id')->first()->lines()->first();
        $this->assertSame($late, (int) $line->lot_id);
    }

    public function test_a_lot_not_offered_for_the_line_is_ignored(): void
    {
        $this->assertNull(LotChoice::picked(['lot_id' => 99, 'lots' => [5 => 'L5']]));
        $this->assertNull(LotChoice::picked(['lot_id' => '', 'lots' => [5 => 'L5']]));
        $this->assertSame(5, LotChoice::picked(['lot_id' => '5', 'lots' => [5 => 'L5']]));
    }
}
