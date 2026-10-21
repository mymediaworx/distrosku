<?php

namespace Tests\Feature;

use App\Credit\CreditLimit;
use App\Credit\CreditLimitException;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase172CreditLimitRoleTest extends TestCase
{
    use RefreshDatabase;

    private Customer $ky;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->ky = Customer::where('code', 'CTEST-KY')->first();
        $this->ky->update(['credit_limit' => 300]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'active' => true]);
    }

    public function test_sales_sees_credit_limit_disabled_and_cannot_change_it_in_filament(): void
    {
        $this->actingAs($this->user('sales'));
        Livewire::test(EditCustomer::class, ['record' => $this->ky->getRouteKey()])
            ->assertFormFieldIsDisabled('credit_limit')
            ->fillForm(['credit_limit' => 99999, 'phone' => '555-0100'])
            ->call('save')->assertHasNoFormErrors();
        $this->ky->refresh();
        $this->assertSame('300.00', (string) $this->ky->credit_limit);
        $this->assertSame('555-0100', $this->ky->phone);
    }

    public function test_admin_roles_can_edit_credit_limit_in_filament(): void
    {
        foreach (['owner', 'admin', 'brandworx_admin'] as $i => $role) {
            $this->actingAs($this->user($role));
            Livewire::test(EditCustomer::class, ['record' => $this->ky->getRouteKey()])
                ->assertFormFieldIsEnabled('credit_limit')
                ->fillForm(['credit_limit' => 1000 + $i])->call('save')->assertHasNoFormErrors();
            $this->assertSame(number_format(1000 + $i, 2, '.', ''), (string) $this->ky->fresh()->credit_limit);
        }
    }

    public function test_server_side_blocks_non_admin_change_but_allows_other_edits(): void
    {
        foreach (['sales', 'warehouse', 'driver'] as $role) {
            $this->actingAs($this->user($role));
            try {
                $this->ky->fresh()->update(['credit_limit' => 5000]);
                $this->fail("$role changed the credit limit");
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('owner or admin', $e->getMessage());
            }
            $this->ky->fresh()->update(['credit_limit' => '300.00', 'phone' => '555-0111']);
            $this->assertSame('300.00', (string) $this->ky->fresh()->credit_limit);
        }
    }

    public function test_non_admin_cannot_create_customer_with_limit_but_can_with_zero(): void
    {
        $this->actingAs($this->user('sales'));
        $base = ['name' => 'TEST New', 'terms' => 'COD'];
        $this->expectException(WorkflowException::class);
        try {
            Customer::create($base + ['code' => 'CTEST-N0', 'credit_limit' => 0]);
        } catch (\Throwable $e) {
            $this->fail('zero limit blocked: '.$e->getMessage());
        }
        Customer::create($base + ['code' => 'CTEST-N1', 'credit_limit' => 50]);
    }

    public function test_console_and_admin_changes_allowed(): void
    {
        $this->ky->update(['credit_limit' => 400]);
        $this->actingAs($this->user('owner'));
        $this->ky->update(['credit_limit' => 450]);
        $this->assertSame('450.00', (string) $this->ky->fresh()->credit_limit);
    }

    public function test_block_message_points_to_admin_override(): void
    {
        try {
            app(CreditLimit::class)->check($this->ky->fresh(), '10000');
            $this->fail('not blocked');
        } catch (CreditLimitException $e) {
            $this->assertStringContainsString('owner/admin can confirm it as a sales order in admin with a credit override', $e->getMessage());
            $this->assertStringNotContainsString('override with a reason', $e->getMessage());
        }
    }
}
