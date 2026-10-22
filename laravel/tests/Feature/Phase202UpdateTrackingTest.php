<?php

namespace Tests\Feature;

use App\Filament\Pages\ExpectedInbound;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrderResource\Pages\ViewPurchaseOrder;
use App\Filament\Support\UpdateTrackingAction;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.2: warehouse / receiving team may update ONLY inbound shipping fields on POs. */
class Phase202UpdateTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $wh;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
        $this->wh = User::factory()->create(['role' => 'warehouse', 'active' => true]);
        $this->main = Warehouse::where('code', 'MAIN')->first();
    }

    private function po(): PurchaseOrder
    {
        $this->actingAs($this->owner);
        $po = PurchaseOrder::create(['vendor_id' => Vendor::first()?->id ?? Vendor::factory()->create()->id, 'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(), 'status' => 'ordered']);
        $po->lines()->create(['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'qty' => 10, 'unit_cost' => 2]);

        return $po->fresh();
    }

    public function test_warehouse_can_update_tracking_from_list_and_alert_and_audit(): void
    {
        $po = $this->po();
        $this->wh->notifications()->delete();
        $this->actingAs($this->wh);
        Livewire::test(ListPurchaseOrders::class)
            ->assertTableActionVisible('updateTracking', $po)
            ->assertTableActionHidden('edit', $po)
            ->callTableAction('updateTracking', $po, ['expected_at' => now()->addDays(2)->toDateString(), 'carrier_code' => 'ups', 'tracking_numbers' => ['1Z111']])
            ->assertHasNoTableActionErrors();
        $po->refresh();
        $this->assertSame(['1Z111'], $po->tracking_numbers);
        $this->assertSame('ups', $po->carrier_code);
        $this->assertSame(1, $this->wh->notifications()->count());
        $this->assertTrue(ActivityLog::where('subject_type', PurchaseOrder::class)->where('subject_id', $po->id)->where('event', 'updated')->exists());
    }

    public function test_warehouse_cannot_change_other_fields(): void
    {
        $po = $this->po();
        $before = [$po->vendor_id, $po->status, (string) $po->total, $po->lines()->value('unit_cost'), $po->lines()->value('qty')];
        $other = Vendor::factory()->create();
        UpdateTrackingAction::apply($po, ['carrier_code' => 'fedex', 'tracking_numbers' => ['77'], 'vendor_id' => $other->id, 'status' => 'received',
            'total' => 999, 'warehouse_id' => 999, 'lines' => [['qty' => 1, 'unit_cost' => 0]]], $this->wh);
        $po->refresh();
        $this->assertSame('fedex', $po->carrier_code);
        $this->assertSame($before, [$po->vendor_id, $po->status, (string) $po->total, $po->lines()->value('unit_cost'), $po->lines()->value('qty')]);
        $this->assertSame($this->main->id, $po->warehouse_id);

        $this->actingAs($this->wh);
        $this->assertFalse(PurchaseOrderResource::canEdit($po));
        $this->assertFalse(PurchaseOrderResource::canCreate());
        $this->get(PurchaseOrderResource::getUrl('edit', ['record' => $po]))->assertForbidden();
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $po]))->assertOk();
    }

    public function test_view_page_and_expected_inbound_offer_update_tracking(): void
    {
        $po = $this->po();
        $this->actingAs($this->wh);
        Livewire::test(ViewPurchaseOrder::class, ['record' => $po->getRouteKey()])
            ->assertActionVisible('updateTracking')
            ->assertActionHidden('edit')
            ->callAction('updateTracking', ['carrier_code' => 'usps', 'tracking_numbers' => ['9400']])
            ->assertHasNoActionErrors();
        $this->assertSame('usps', $po->fresh()->carrier_code);
        Livewire::test(ExpectedInbound::class)->assertTableActionVisible('updateTracking', $po)
            ->callTableAction('updateTracking', $po, ['carrier_code' => 'dhl', 'tracking_numbers' => ['55']]);
        $this->assertSame(['55'], $po->fresh()->tracking_numbers);
    }

    public function test_receiving_team_member_and_others(): void
    {
        $po = $this->po();
        $sales = User::factory()->create(['role' => 'sales', 'active' => true]);
        $this->assertFalse(UpdateTrackingAction::allowed($sales, $po));
        $this->main->receivers()->attach($sales->id);
        $this->assertTrue(UpdateTrackingAction::allowed($sales, $po->fresh()));
        $driver = User::factory()->create(['role' => 'driver', 'active' => true]);
        $this->expectException(AuthorizationException::class);
        UpdateTrackingAction::apply($po, ['carrier_code' => 'ups'], $driver);
    }

    public function test_owner_keeps_full_edit_and_has_update_tracking(): void
    {
        $po = $this->po();
        $this->actingAs($this->owner);
        $this->assertTrue(PurchaseOrderResource::canEdit($po));
        Livewire::test(EditPurchaseOrder::class, ['record' => $po->getRouteKey()])->assertActionVisible('updateTracking');
        $this->assertSame('0.20.2', config('erp.version'));
    }
}
