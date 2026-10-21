<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Livewire\CounterPos;
use App\Livewire\PosLock;
use App\Livewire\PosPinSetup;
use App\Models\PosPinLogin;
use App\Models\User;
use App\Pos\PosPin;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class PosPinTabletTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->sales = User::factory()->create(['role' => 'sales', 'name' => 'Sam Sales', 'password' => 'Secret12345']);
        app(PosPin::class)->set($this->sales, '4826');
        RateLimiter::clear(PosPin::key($this->sales));
    }

    public function test_pin_is_hashed_unique_and_validated(): void
    {
        $u = $this->sales->fresh();
        $this->assertNotSame('4826', $u->pos_pin_hash);
        $this->assertStringNotContainsString('4826', $u->pos_pin_hash.$u->pos_pin_lookup);
        $this->assertArrayNotHasKey('pos_pin_hash', $u->toArray());
        $other = User::factory()->create(['role' => 'sales']);
        foreach (['4826', '12a4', '123', '1234'] as $bad) {
            try {
                app(PosPin::class)->set($other, $bad);
                $this->fail("accepted $bad");
            } catch (WorkflowException) {
            }
        }
        app(PosPin::class)->set($other, '5937');
        $this->assertSame(2, User::whereNotNull('pos_pin_lookup')->count());
    }

    public function test_user_sets_own_pin_with_password(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'password' => 'Pass12345!']);
        $this->actingAs($u);
        Livewire::test(PosPinSetup::class)->set('password', 'wrong')->set('pin', '7391')->set('pin_confirmation', '7391')->call('save')->assertSee('Current password is wrong');
        Livewire::test(PosPinSetup::class)->set('password', 'Pass12345!')->set('pin', '7391')->set('pin_confirmation', '7391')->call('save')->assertSee('PIN saved');
        $this->assertNotNull($u->fresh()->pos_pin_hash);
    }

    public function test_lock_screen_needs_enrolled_terminal(): void
    {
        $this->get('/pos/lock')->assertForbidden();
        $this->get('/pos')->assertRedirect('/admin/login');
        // password login on /pos enrolls the device
        $this->actingAs(User::factory()->create(['role' => 'sales']))->get('/pos')->assertOk()->assertCookie(PosPin::TERMINAL_COOKIE);
    }

    public function test_pin_login_opens_pos_only_and_is_logged(): void
    {
        $this->withCookie(PosPin::TERMINAL_COOKIE, '1');
        Livewire::withCookie(PosPin::TERMINAL_COOKIE, '1')->test(PosLock::class)
            ->assertSee('Sam Sales')->call('choose', $this->sales->id)
            ->call('digit', '4')->call('digit', '8')->call('digit', '2')->call('digit', '6')
            ->assertRedirect(route('pos'));
        $this->assertSame($this->sales->id, session(PosPin::SESSION_USER));
        $this->assertFalse(auth('web')->check());
        $this->get('/pos')->assertOk()->assertSee('Sam Sales');
        $this->get('/admin')->assertRedirect('/admin/login');   // PIN never opens admin
        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->assertSame(1, PosPinLogin::where('event', 'success')->count());
    }

    public function test_five_wrong_pins_lock_the_employee(): void
    {
        $lw = Livewire::withCookie(PosPin::TERMINAL_COOKIE, '1')->test(PosLock::class)->call('choose', $this->sales->id);
        for ($i = 0; $i < 5; $i++) {
            $lw->set('pin', '000')->call('digit', '1');
        }
        $lw->assertSee('locked');
        $lw->set('pin', '482')->call('digit', '6')->assertSee('locked')->assertNoRedirect();
        $this->assertSame(5, PosPinLogin::where('event', 'failed')->count());
        $this->assertSame(1, PosPinLogin::where('event', 'locked')->count());
        $this->assertNull(session(PosPin::SESSION_USER));
    }

    public function test_idle_ten_minutes_returns_to_lock_and_keeps_cart(): void
    {
        $this->withCookie(PosPin::TERMINAL_COOKIE, '1');
        $this->withSession([PosPin::SESSION_USER => $this->sales->id, PosPin::SESSION_SEEN => time() - 30,
            'pos_cart' => ['cart' => [['product_id' => 1, 'sku' => 'X', 'name' => 'X', 'qty' => '2', 'price' => '1', 'reasons' => []]]]]);
        $this->get('/pos')->assertOk();
        $this->withSession([PosPin::SESSION_SEEN => time() - PosPin::IDLE_SECONDS - 5]);
        $this->get('/pos')->assertRedirect(route('pos.lock'));
        $this->assertNull(session(PosPin::SESSION_USER));
        $this->assertCount(1, session('pos_cart.cart'));
    }

    public function test_pos_steppers_tender_keypad_and_cart_kept_across_mounts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $lw = Livewire::test(CounterPos::class)->set('scan', 'TST-GEN-001')->call('add')->call('inc', 0)->call('inc', 0);
        $this->assertSame('3', $lw->get('cart')[0]['qty']);
        $lw->call('dec', 0);
        $this->assertSame('2', $lw->get('cart')[0]['qty']);
        Livewire::test(CounterPos::class)->assertSet('cart.0.sku', 'TST-GEN-001'); // restored from the terminal session
        $lw->set('tendered', '100')->assertSee('Change due');
        $lw->call('dec', 0)->call('dec', 0)->assertSet('cart', []);
    }

    public function test_suspension_blocks_pin_sessions_and_lock_screen(): void
    {
        $this->withCookie(PosPin::TERMINAL_COOKIE, '1');
        $this->withSession([PosPin::SESSION_USER => $this->sales->id, PosPin::SESSION_SEEN => time()]);
        $this->get('/pos')->assertOk();
        \App\Models\Company::current()->update(['suspended' => true]);
        $this->get('/pos')->assertStatus(403);
        $this->assertNull(session(PosPin::SESSION_USER));
        $this->get('/pos/lock')->assertStatus(403);
        try {
            app(PosPin::class)->attempt($this->sales, '4826', '1.2.3.4');
            $this->fail('PIN accepted while suspended');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('suspended', $e->getMessage());
        }
        // a brandworx_admin with a password still gets in
        $bw = User::factory()->create(['role' => 'brandworx_admin']);
        $this->actingAs($bw)->get('/pos')->assertOk();
        \App\Models\Company::current()->update(['suspended' => false]);
    }
}
