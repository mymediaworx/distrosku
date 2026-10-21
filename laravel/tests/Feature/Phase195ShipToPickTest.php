<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Livewire\Driver\VanSale;
use App\Livewire\Portal\Catalog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\ShipTo;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.19.5: POS / van / portal always show a ship-to when the customer has one. */
class Phase195ShipToPickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    }

    private function customer(string $name, int $shipTos, bool $clearDefault = false): Customer
    {
        $c = Customer::create(['code' => strtoupper(substr(md5($name), 0, 8)), 'name' => $name, 'state' => 'KY']);
        for ($i = 1; $i <= $shipTos; $i++) {
            ShipTo::create(['customer_id' => $c->id, 'name' => "$name store $i", 'state' => 'KY', 'city' => 'Louisville']);
        }
        if ($clearDefault) {
            ShipTo::where('customer_id', $c->id)->update(['is_default' => false]); // legacy rows: no default flag at all
        }

        return $c;
    }

    public function test_pos_one_ship_to_is_selected(): void
    {
        $c = $this->customer('ABC Convenience Store', 1);
        Livewire::test(CounterPos::class)->set('customerId', $c->id)
            ->assertSet('shipToId', $c->shipTos()->value('id'))->assertSee('ABC Convenience Store store 1')->assertDontSee('No ship-to');
    }

    public function test_pos_no_ship_to_shows_add_link(): void
    {
        $c = $this->customer('Empty Mart', 0);
        Livewire::test(CounterPos::class)->set('customerId', $c->id)
            ->assertSet('shipToId', null)->assertSee('No ship-to')->assertSee('add one')->assertSee('/admin/customers/'.$c->id.'/edit');
    }

    public function test_pos_several_without_default_picks_first_and_can_change(): void
    {
        $c = $this->customer('Multi Mart', 3, true);
        $ids = $c->shipTos()->orderBy('id')->pluck('id');
        $lw = Livewire::test(CounterPos::class)->set('customerId', $c->id)->assertSet('shipToId', $ids[0]);
        $lw->set('shipToId', $ids[2])->assertSet('shipToId', $ids[2]);
        // a ship-to of another customer is never kept
        $other = Customer::where('code', 'CTEST-KY')->first();
        $lw->set('shipToId', $other->shipTos()->value('id'))->assertSet('shipToId', $ids[0]);
    }

    public function test_pos_default_wins_and_stale_session_ship_to_is_replaced(): void
    {
        $c = $this->customer('Default Mart', 2);
        $b = $c->shipTos()->orderByDesc('id')->first();
        $b->update(['is_default' => true]);
        $other = Customer::where('code', 'CTEST-KY')->first();
        session(['pos_cart' => ['cart' => [], 'customerId' => $c->id, 'shipToId' => $other->shipTos()->value('id')]]);
        Livewire::test(CounterPos::class)->assertSet('customerId', $c->id)->assertSet('shipToId', $b->id);
    }

    public function test_van_picks_default_or_only_ship_to(): void
    {
        $one = $this->customer('Van One', 1);
        $many = $this->customer('Van Many', 3, true);
        $many->shipTos()->orderByDesc('id')->first()->update(['is_default' => true]);
        $none = $this->customer('Van None', 0);
        $drv = User::where('role', 'driver')->first();
        $lw = Livewire::actingAs($drv)->test(VanSale::class);
        $lw->set('customerId', $one->id)->assertSet('shipToId', $one->shipTos()->value('id'))->assertSee('Van One store 1');
        $lw->set('customerId', $many->id)->assertSet('shipToId', $many->defaultShipTo()->id);
        $lw->set('customerId', $none->id)->assertSet('shipToId', null)->assertSee('No ship-to');
    }

    public function test_portal_uses_default_ship_to(): void
    {
        $c = $this->customer('Portal Mart', 3, true);
        $def = $c->shipTos()->orderByDesc('id')->first();
        $def->update(['is_default' => true]);
        $k = CustomerContact::create(['customer_id' => $c->id, 'name' => 'Pat', 'email' => 'pat@example.test']);
        $k->setPasswordFromLink('Secret12345');
        Auth::guard('portal')->login($k->fresh());
        Livewire::test(Catalog::class)->assertSet('shipToId', $def->id);
    }
}
