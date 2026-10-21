<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPriceOverride;
use App\Models\EligibilityRule;
use App\Models\Inventory;
use App\Models\PosHeldSale;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Pos\PosPin;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase193PosBrowseHoldTest extends TestCase
{
    use RefreshDatabase;

    private Customer $ky;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
    }

    private function sales(): User
    {
        $u = User::factory()->create(['role' => 'sales', 'active' => true]);
        $this->actingAs($u);

        return $u;
    }

    public function test_browse_by_category_filter_tiles_and_keypad_add(): void
    {
        $p = Product::where('sku', 'TST-GEN-001')->first();
        CustomerPriceOverride::create(['product_id' => $p->id, 'customer_id' => $this->ky->id, 'price' => 19.5, 'duration' => 'ongoing', 'active' => true]);
        $this->sales();
        $lw = Livewire::test(CounterPos::class)->set('customerId', $this->ky->id)->call('openBrowse')->assertSee('Browse catalog');
        $this->assertContains('Sundries', $lw->get('browseFacets')['categories']);
        $lw->call('browseBy', 'Sundries', null);
        $tiles = collect($lw->instance()->browseTiles);
        $this->assertSame(['TST-GEN-001'], $tiles->pluck('sku')->unique()->values()->all());
        $t = $tiles->first();
        $this->assertSame('19.5000', $t['price']); // price for the selected customer
        $this->assertNotSame('', $t['stock']);
        $lw->call('browseBy', null, null)->set('browseFilter', 'Pipe');
        $this->assertSame(['TST-PIP-001'], collect($lw->instance()->browseTiles)->pluck('sku')->all());
        $lw->call('pick', $p->id)->call('pickKey', 'clear')->call('pickKey', '1')->call('pickKey', '2')->call('pickKey', 'back')->call('pickKey', '3')
            ->assertSet('pickQty', '13')->call('addPicked')
            ->assertSet('cart.0.sku', 'TST-GEN-001')->assertSet('cart.0.qty', '13')->assertSet('cart.0.source', 'override');
        $lw->call('pick', $p->id)->call('pickKey', '2')->assertSet('pickQty', '2')->call('addPicked')->assertSet('cart.0.qty', '15'); // first key replaces the 1; same line
    }

    public function test_browse_respects_eligibility_like_scan(): void
    {
        $this->sales();
        EligibilityRule::create(['name' => 'TEST block hemp KY', 'level' => 'state', 'state' => 'KY', 'subtype' => 'hemp', 'action' => 'block', 'effective_from' => '2020-01-01', 'enabled' => true]);
        $h = Product::where('sku', 'TST-HMP-001')->first();
        $h->update(['product_subtype' => 'hemp']);
        $lw = Livewire::test(CounterPos::class)->set('customerId', $this->ky->id)->call('openBrowse')->call('pick', $h->id)->call('addPicked');
        $this->assertNotEmpty($lw->get('cart.0.reasons'));
        $lw->call('checkout')->assertSee('Remove blocked items');
        $scan = Livewire::test(CounterPos::class)->set('cart', [])->set('customerId', $this->ky->id)->set('scan', 'TST-HMP-001')->call('add');
        $this->assertEquals($lw->get('cart.0.reasons'), $scan->get('cart.0.reasons'));
        $h->update(['archived_at' => now()]);
        $this->assertSame([], collect(Livewire::test(CounterPos::class)->call('openBrowse')->set('browseFilter', 'Delta')->instance()->browseTiles)->all());
    }

    public function test_hold_clears_screen_reserves_nothing_and_recall_reprices(): void
    {
        $u = $this->sales();
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $inv = Inventory::where('product_id', $p->id)->where('warehouse_id', Warehouse::where('code', 'MAIN')->value('id'))->first();
        $reserved = (string) $inv->reserved;
        $lw = Livewire::test(CounterPos::class)->set('cart', [])->set('customerId', $this->ky->id)->set('scan', 'TST-GEN-001')->call('add')->call('inc', 0)
            ->set('notes', 'Back door')->set('holdLabel', 'Joe pickup')->call('hold')
            ->assertSet('cart', [])->assertSet('notes', '')->assertSee('Sale held');
        $h = PosHeldSale::first();
        $this->assertSame('Joe pickup', $h->label);
        $this->assertSame($this->ky->id, $h->customer_id);
        $this->assertSame($u->id, $h->user_id);
        $this->assertSame('Back door', $h->notes);
        $this->assertSame('2', $h->cart[0]['qty']);
        $this->assertTrue($h->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame($reserved, (string) $inv->fresh()->reserved);
        $this->assertTrue(ActivityLog::where('event', 'pos_hold')->exists());

        // price changes while held; another user at the same location recalls it
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        CustomerPriceOverride::create(['product_id' => $p->id, 'customer_id' => $this->ky->id, 'price' => 20, 'duration' => 'ongoing', 'active' => true]);
        $this->sales();
        $r = Livewire::test(CounterPos::class)->set('cart', [])->set('recalling', true)->assertSee('Joe pickup')->call('recall', $h->id)
            ->assertSet('customerId', $this->ky->id)->assertSet('notes', 'Back door')->assertSet('cart.0.price', '20.0000')->assertSet('cart.0.qty', '2');
        $this->assertStringContainsString('Price changed: TST-GEN-001', implode(' ', $r->get('recallNotes')));
        $this->assertNull(PosHeldSale::find($h->id));
        $this->assertTrue(ActivityLog::where('event', 'pos_recall')->exists());
    }

    public function test_recall_flags_stock_and_credit_and_location_scope(): void
    {
        $this->sales();
        $wh = Warehouse::where('code', 'MAIN')->first();
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $this->ky->forceFill(['credit_limit' => 10])->saveQuietly();
        $h = PosHeldSale::create(['customer_id' => $this->ky->id, 'warehouse_id' => $wh->id, 'tender' => 'account', 'total' => 2500, 'expires_at' => now()->addDay(),
            'cart' => [['product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'qty' => '100000', 'price' => '25.0000', 'quoted' => '25.0000', 'source' => 'list']]]);
        $other = PosHeldSale::create(['warehouse_id' => Warehouse::where('code', '!=', 'MAIN')->value('id'), 'cart' => [], 'expires_at' => now()->addDay()]);
        $lw = Livewire::test(CounterPos::class)->set('cart', [])->set('warehouseId', $wh->id)->set('recalling', true);
        $this->assertSame([$h->id], $lw->instance()->heldSales->pluck('id')->all()); // other location not listed
        $lw->call('recall', $other->id)->assertSet('error', 'That held sale is gone (recalled, deleted or expired).');
        $notes = implode(' ', $lw->call('recall', $h->id)->get('recallNotes'));
        $this->assertStringContainsString('Low stock: TST-GEN-001', $notes);
        $this->assertStringContainsString('Credit: available', $notes);
    }

    public function test_only_admin_deletes_and_expiry_is_configurable_and_logged(): void
    {
        $this->sales();
        Setting::put('pos_hold_days', '2');
        $this->assertSame(2, PosHeldSale::days());
        $h = PosHeldSale::create(['cart' => [], 'warehouse_id' => Warehouse::value('id'), 'expires_at' => now()->addDays(2)]);
        Livewire::test(CounterPos::class)->call('deleteHeld', $h->id)->assertSet('error', 'Only an owner or admin can delete a held sale.');
        $this->assertNotNull($h->fresh());
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        Livewire::test(CounterPos::class)->call('deleteHeld', $h->id);
        $this->assertNull($h->fresh());
        $this->assertTrue(ActivityLog::where('event', 'pos_hold_deleted')->exists());
        $old = PosHeldSale::create(['cart' => [], 'expires_at' => now()->subMinute()]);
        $this->artisan('pos:expire-holds')->expectsOutputToContain('Expired 1')->assertExitCode(0);
        $this->assertNull($old->fresh());
        $this->assertTrue(ActivityLog::where('event', 'pos_hold_expired')->exists());
    }

    public function test_held_sale_and_cart_survive_pin_lock(): void
    {
        $u = $this->sales();
        $lw = Livewire::test(CounterPos::class)->set('cart', [])->set('scan', 'TST-GEN-001')->call('add')->call('hold');
        $lw->set('scan', 'TST-PIP-001')->call('add')->set('notes', 'in progress')->call('lock')->assertRedirect(route('pos.lock'));
        $this->withSession([PosPin::SESSION_USER => $u->id, PosPin::SESSION_SEEN => time()]);
        $again = Livewire::test(CounterPos::class)->assertSet('cart.0.sku', 'TST-PIP-001')->assertSet('notes', 'in progress');
        $this->assertSame(1, PosHeldSale::count());
        $again->set('recalling', true)->call('recall', PosHeldSale::first()->id)->assertSet('error', 'Finish, hold or clear the current sale first.');
    }
}
