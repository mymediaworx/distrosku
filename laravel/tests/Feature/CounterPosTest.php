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

class CounterPosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/pos')->assertRedirect('/admin/login');
    }

    /** 0.21: a licensed COD counter customer (CASH and walk-in customers can't buy regulated products). */
    private function codCustomer(): \App\Models\Customer
    {
        $cash = \App\Models\Customer::where('code', 'CASH')->first();
        $c = \App\Models\Customer::create(array_merge($cash->only(['name', 'type', 'terms', 'credit_limit', 'state']), ['code' => 'CODLIC', 'name' => 'Licensed COD Counter', 'active' => true, 'walk_in' => false]));
        foreach ($cash->shipTos as $st) {
            $c->shipTos()->create($st->only(['name', 'address1', 'city', 'state', 'zip']));
        }
        foreach ($cash->licenses as $l) {
            $c->licenses()->create(array_merge($l->only(['type', 'state', 'expires_at']), ['number' => 'COD-'.$l->number]));
        }

        return $c;
    }

    public function test_pos_sale_completes_and_posts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        Livewire::test(CounterPos::class)
            ->set('customerId', $this->codCustomer()->id) // 0.21: CASH can't buy regulated (TST-CIG-001)
            ->set('scan', 'TST-GEN-001')->call('add')
            ->set('scan', 'TST-CIG-001')->call('add')
            ->call('checkout')
            ->assertSet('cart', [])
            ->assertSee('complete');

        $so = SalesOrder::where('channel', 'pos')->first();
        $this->assertSame('invoiced', $so->status->value);
        $this->assertSame('87.5000', $so->total);
        $this->assertSame('87.5000', $so->amount_paid);
    }

    public function test_pos_blocks_pact_item_for_out_of_state_ship_to(): void
    {
        $this->actingAs(User::factory()->create());
        $in = Customer::where('code', 'CTEST-IN')->first();
        Livewire::test(CounterPos::class)
            ->set('customerId', $in->id)
            ->set('scan', 'TST-KRT-001')->call('add')
            ->assertSee('banned in IN')
            ->call('checkout')
            ->assertSee('Remove blocked items');
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['', '/products', '/sales-orders', '/sales-orders/create', '/purchase-orders', '/purchase-orders/create', '/customers', '/inventories',
            '/stock-movements', '/journals', '/accounts', '/compliance-rule-versions', '/delivery-manifests/create', '/users/create', '/buydowns', '/payments'] as $p) {
            $this->get('/admin'.$p)->assertOk();
        }
    }
}
