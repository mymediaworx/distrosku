<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\ShipTosRelationManager;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class Phase193ShipToTabTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->c = Customer::where('code', 'CTEST-KY')->first();
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['role' => $role, 'active' => true]);
        $this->actingAs($u);

        return $u;
    }

    private function rm()
    {
        return Livewire::test(ShipTosRelationManager::class, ['ownerRecord' => $this->c, 'pageClass' => EditCustomer::class]);
    }

    public function test_tab_create_edit_default_delete_inline_for_sales(): void
    {
        $this->as('sales');
        $this->get("/admin/customers/{$this->c->id}/edit")->assertOk();
        $this->assertTrue(ShipTosRelationManager::canViewForRecord($this->c, EditCustomer::class));
        $this->assertContains(ShipTosRelationManager::class, \App\Filament\Resources\CustomerResource::getRelations());
        $first = $this->c->shipTos()->first();
        $this->assertTrue($first->fresh()->is_default); // backfilled / first is default
        $this->rm()->assertCanSeeTableRecords([$first])
            ->callTableAction('create', data: ['name' => 'Second store', 'address' => '2 Main', 'city' => 'Paris', 'county' => 'Bourbon', 'state' => 'KY', 'zip' => '40361', 'is_default' => true])
            ->assertHasNoTableActionErrors();
        $new = ShipTo::where('name', 'Second store')->first();
        $this->assertSame('Bourbon', $new->county);
        $this->assertTrue($new->is_default);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame($new->id, $this->c->defaultShipTo()->id);
        $this->rm()->callTableAction('makeDefault', $first->fresh());
        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($new->fresh()->is_default);
        $this->rm()->callTableAction('edit', $new, data: ['city' => 'Lexington', 'county' => 'Fayette'])->assertHasNoTableActionErrors();
        $this->assertSame('Fayette', $new->fresh()->county);
        $this->rm()->callTableAction('delete', $new);
        $this->assertNull(ShipTo::find($new->id));
    }

    public function test_used_ship_to_cannot_be_deleted_and_default_moves(): void
    {
        $this->as('owner');
        $st = $this->c->shipTos()->first();
        SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $st->id, 'warehouse_id' => Warehouse::value('id'), 'order_date' => now()->toDateString()]);
        $this->rm()->callTableAction('delete', $st)->assertNotified('This ship-to is used on orders and cannot be deleted.');
        $this->assertNotNull($st->fresh());
        $b = ShipTo::create(['customer_id' => $this->c->id, 'name' => 'B', 'state' => 'KY']);
        $this->assertFalse($b->fresh()->is_default);
        $b->update(['is_default' => true]);
        $b->delete();
        $this->assertTrue($st->fresh()->is_default); // default moves back
    }

    public function test_permissions_unchanged(): void
    {
        $this->as('warehouse');
        $this->assertFalse(ShipTosRelationManager::canViewForRecord($this->c, EditCustomer::class));
        $this->get('/admin/ship-tos')->assertForbidden();
        $this->as('sales');
        $this->get('/admin/ship-tos')->assertOk(); // route kept, not in the menu
        $this->get('/admin/customers')->assertOk()->assertDontSee('href="'.url('/admin/ship-tos').'"', false);
    }

    public function test_new_order_picks_default_and_migration_rerunnable(): void
    {
        $b = ShipTo::create(['customer_id' => $this->c->id, 'name' => 'Default B', 'state' => 'KY', 'is_default' => true]);
        $this->as('owner');
        Livewire::test(\App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder::class)
            ->fillForm(['customer_id' => $this->c->id])->assertFormSet(['ship_to_id' => $b->id]);
        $m = include database_path('migrations/2026_11_14_000001_phase193_ship_to_default.php');
        $m->up();
        $this->assertTrue(Schema::hasColumn('ship_tos', 'is_default'));
        $this->assertSame(1, ShipTo::where('customer_id', $this->c->id)->where('is_default', true)->count());
    }
}
