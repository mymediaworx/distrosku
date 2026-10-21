<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase173CustomerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function cust(string $code): Customer
    {
        return Customer::create(['code' => $code, 'name' => "TEST $code", 'terms' => 'COD', 'type' => 'retailer']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    public function test_sales_cannot_see_or_use_delete(): void
    {
        $c = $this->cust('CTEST-D1');
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        $this->assertFalse(CustomerResource::canDelete($c));
        $this->assertFalse(CustomerResource::canDeleteAny());
        Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()])->assertActionHidden('delete');
        Livewire::test(ListCustomers::class)->assertTableBulkActionHidden('delete');
        $n = ActivityLog::where('event', 'customer_delete_blocked')->count();
        try {
            $c->delete();
            $this->fail('deleted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('owner or admin', $e->getMessage());
        }
        try { // bulk-style
            Customer::whereKey($c->id)->get()->each->delete();
            $this->fail('bulk deleted');
        } catch (WorkflowException) {
        }
        $this->assertNotNull(Customer::find($c->id));
        $this->assertSame($n + 2, ActivityLog::where('event', 'customer_delete_blocked')->count());
    }

    public function test_admin_roles_can_delete(): void
    {
        foreach (['owner', 'admin', 'brandworx_admin'] as $i => $role) {
            $c = $this->cust("CTEST-A$i");
            $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
            $this->assertTrue(CustomerResource::canDelete($c));
            Livewire::test(ListCustomers::class)->assertTableBulkActionVisible('delete')
                ->callTableBulkAction('delete', [$c]);
            $this->assertNull(Customer::find($c->id));
        }
    }
}
