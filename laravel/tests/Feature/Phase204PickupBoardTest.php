<?php

namespace Tests\Feature;

use App\Enums\SalesOrderStatus as S;
use App\Filament\Pages\CounterPickupBoard;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Sales\PickupBoard;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.4: Counter pickup board. */
class Phase204PickupBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $ky;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
        $this->main = Warehouse::where('code', 'MAIN')->first();
        $this->actingAs($this->owner);
    }

    private function confirmed(string $sku = 'TST-GEN-001', array $extra = []): SalesOrder
    {
        $so = SalesOrder::create(array_merge(['customer_id' => $this->ky->id, 'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(), 'fulfillment' => 'pickup'], $extra));
        $so->lines()->create(['product_id' => Product::where('sku', $sku)->value('id'), 'qty' => 3, 'unit_price' => '10']);
        $svc = app(SalesOrderService::class);

        return $svc->confirm($svc->recalc($so->fresh())->fresh())->fresh();
    }

    public function test_columns_and_exclusions(): void
    {
        $a = $this->confirmed();
        $b = $this->confirmed();
        PickupBoard::startPicking($b);
        $c = $this->confirmed();
        PickupBoard::markReady($c);
        $d = $this->confirmed();
        PickupBoard::pickedUp($d->fresh());
        $del = $this->confirmed('TST-GEN-001', ['fulfillment' => 'delivery', 'ship_to_id' => $this->ky->shipTos()->value('id')]);
        $pos = $this->confirmed('TST-GEN-001', ['channel' => 'pos']);
        app(SalesOrderService::class)->deliver($pos);

        $cols = PickupBoard::columns();
        $ids = fn ($k) => $cols[$k]->pluck('id')->all();
        $this->assertContains($a->id, $ids('to_pick'));
        $this->assertSame([$b->id], $ids('picking'));
        $this->assertSame([$c->id], $ids('ready'));
        $this->assertSame([$d->id], $ids('done'));
        $all = collect($cols)->flatten()->pluck('id');
        $this->assertNotContains($del->id, $all);
        $this->assertNotContains($pos->id, $all);
        $this->assertSame(S::Picked, $c->fresh()->status);
        $this->assertSame(S::Invoiced, $d->fresh()->status);
        $this->assertTrue(ActivityLog::where('event', 'pickup_board_stage')->where('subject_id', $b->id)->exists());
        $this->assertCount(0, PickupBoard::columns(null, 'zzz-nomatch')['to_pick']);
        $this->assertContains($a->id, PickupBoard::columns(null, $a->number)['to_pick']->pluck('id')->all());
    }

    public function test_age_colors(): void
    {
        $o = $this->confirmed();
        $this->assertSame('gray', PickupBoard::ageColor($o));
        $o->forceFill(['confirmed_at' => now()->subHours(3)])->saveQuietly();
        $this->assertSame('amber', PickupBoard::ageColor($o->fresh()));
        $o->forceFill(['confirmed_at' => now()->subHours(25)])->saveQuietly();
        $this->assertSame('red', PickupBoard::ageColor($o->fresh()));
        $o->forceFill(['confirmed_at' => now(), 'ship_date' => now()->subDay()->toDateString()])->saveQuietly();
        $this->assertSame('red', PickupBoard::ageColor($o->fresh()));
    }

    public function test_regulated_pickup_needs_signature(): void
    {
        config(['erp.require_signature' => true]);
        $o = $this->confirmed('TST-CIG-001');
        $this->assertTrue(PickupBoard::needsSignature($o->load('lines.product')));
        PickupBoard::markReady($o);
        $this->expectException(\Throwable::class);
        PickupBoard::pickedUp($o->fresh());
    }

    public function test_page_actions_and_collect(): void
    {
        $o = $this->confirmed();
        Livewire::test(CounterPickupBoard::class)->assertOk()->assertSee($o->number)
            ->callAction('startPicking', arguments: ['id' => $o->id])
            ->callAction('markReady', arguments: ['id' => $o->id]);
        $this->assertSame(S::Picked, $o->fresh()->status);
        Livewire::test(CounterPickupBoard::class)
            ->callAction('collect', data: ['method' => 'cash', 'amount' => $o->fresh()->balance()], arguments: ['id' => $o->id]);
        $o->refresh();
        $this->assertSame(S::Invoiced, $o->status);
        $this->assertSame('done', PickupBoard::stage($o));
        $this->assertEqualsWithDelta(0, (float) $o->balance(), 0.001);
        Livewire::test(CounterPickupBoard::class)->call('toggleTv')->assertSet('tv', true)->assertSee('Normal view');
    }

    public function test_permissions(): void
    {
        $o = $this->confirmed();
        $wh = User::factory()->create(['role' => 'warehouse', 'active' => true]);
        $this->actingAs($wh);
        $this->assertTrue(CounterPickupBoard::canAccess());
        Livewire::test(CounterPickupBoard::class)->callAction('markReady', arguments: ['id' => $o->id]);
        $this->assertSame(S::Picked, $o->fresh()->status);
        $this->assertFalse(PickupBoard::canHandOver($wh) && ! $wh->canModule('sales_orders', 'manage'));
        if (! $wh->canModule('sales_orders', 'manage')) {
            try {
                Livewire::test(CounterPickupBoard::class)->callAction('pickedUp', arguments: ['id' => $o->id]);
            } catch (\Throwable) {
            }
            $this->assertSame(S::Picked, $o->fresh()->status);
        }
        $driver = User::factory()->create(['role' => 'driver', 'active' => true]);
        $this->actingAs($driver);
        $this->assertSame($driver->canModule('sales_orders', 'view') || $driver->canModule('warehouse_scan', 'view') || $driver->canModule('pickup_signature', 'view'), CounterPickupBoard::canAccess());
    }

    public function test_bell_alert_on_confirm(): void
    {
        $wh = User::factory()->create(['role' => 'warehouse', 'active' => true]);
        $o = $this->confirmed();
        $this->assertTrue($wh->notifications()->where('data', 'like', '%'.$o->number.'%')->exists());
        $this->assertTrue(ActivityLog::where('event', 'pickup_board_alert')->where('subject_id', $o->id)->exists());
        $n = $wh->notifications()->count();
        $this->confirmed('TST-GEN-001', ['fulfillment' => 'delivery', 'ship_to_id' => $this->ky->shipTos()->value('id')]);
        $this->assertSame($n, $wh->notifications()->count());
    }

    public function test_version(): void
    {
        $this->assertSame('0.20.4', config('erp.version'));
    }

    public function test_migration_rerunnable(): void
    {
        $m = require database_path('migrations/2026_11_19_000001_phase204_pickup_board.php');
        $m->up();
        $m->up();
        $this->assertTrue(\Schema::hasColumn('sales_orders', 'board_stage'));
    }
}
