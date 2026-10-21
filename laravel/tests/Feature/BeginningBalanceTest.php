<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerBalanceAudit;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\CustomerBalanceService;
use Database\Seeders\CoreSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BeginningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->c = Customer::create(['code' => 'BB1', 'name' => 'Migrated Co', 'type' => 'retailer', 'terms' => 'net30', 'credit_limit' => 0, 'active' => true]);
    }

    private function bal(string $code): string
    {
        $id = Account::where('code', $code)->value('id');

        return bcsub((string) JournalLine::where('account_id', $id)->sum('debit'), (string) JournalLine::where('account_id', $id)->sum('credit'), 4);
    }

    private function svc(): CustomerBalanceService
    {
        return app(CustomerBalanceService::class);
    }

    public function test_admin_roles_post_delta_and_audit(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->svc()->setBeginningBalance($this->c, '1000.50', '2026-09-30', $owner);
        $this->assertSame('1000.5000', $this->bal('1100'));
        $this->assertSame('-1000.5000', $this->bal('3900'));
        $this->assertSame('1000.5000', $this->c->fresh()->balance());

        // decrease: posts only the delta, reversing direction
        $this->svc()->setBeginningBalance($this->c->fresh(), '400', null, User::factory()->create(['role' => 'brandworx_admin']));
        $this->assertSame('400.0000', $this->bal('1100'));
        // to a credit balance
        $this->svc()->setBeginningBalance($this->c->fresh(), '-75.25', null, User::factory()->create(['role' => 'admin']));
        $this->assertSame('-75.2500', $this->bal('1100'));
        $this->assertSame('75.2500', $this->bal('3900'));
        $this->assertSame('-75.2500', $this->c->fresh()->balance());

        $a = CustomerBalanceAudit::where('customer_id', $this->c->id)->orderBy('id')->get();
        $this->assertCount(3, $a);
        $this->assertSame($owner->id, $a[0]->user_id);
        $this->assertSame('0.0000', $a[0]->old_balance);
        $this->assertNotNull($a[2]->journal_id);
        $this->assertSame(Customer::class, $a[0]->journal->source_type);
        // no-op change does not post
        $this->assertNull($this->svc()->setBeginningBalance($this->c->fresh(), '-75.25', null, $owner));
    }

    public function test_non_admin_roles_cannot_set_and_cannot_mass_assign(): void
    {
        foreach (['sales', 'warehouse', 'driver'] as $role) {
            try {
                $this->svc()->setBeginningBalance($this->c, '10', null, User::factory()->create(['role' => $role]));
                $this->fail("$role allowed");
            } catch (AuthorizationException) {
            }
        }
        $this->c->update(['beginning_balance' => 999]);
        $this->assertSame('0.0000', $this->c->fresh()->beginning_balance);
        $this->assertArrayNotHasKey('beginning_balance', $this->c->fresh()->toArray());
        $this->assertSame('0.0000', $this->bal('1100'));
    }

    public function test_payments_apply_against_beginning_balance_and_aging(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->svc()->setBeginningBalance($this->c, '500', now()->subDays(45)->toDateString(), $owner);
        $p = $this->svc()->payOpening($this->c->fresh(), '200', 'check', '1001');
        $this->assertTrue((bool) $p->applies_to_opening);
        $this->assertSame('300.0000', $this->c->fresh()->balance());
        $this->assertSame('300.0000', $this->bal('1100'));
        $this->assertSame('200.0000', $this->bal('1000'));
        $aging = $this->svc()->aging($this->c->fresh());
        $this->assertSame('300.0000', $aging['31-60']);
        $this->assertSame('300.0000', $aging['total']);

        $this->expectException(WorkflowException::class);
        $this->svc()->payOpening($this->c->fresh(), '300.01');
    }

    public function test_cannot_reduce_below_applied_payments(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->svc()->setBeginningBalance($this->c, '500', null, $owner);
        $this->svc()->payOpening($this->c->fresh(), '200');
        $this->expectException(WorkflowException::class);
        $this->svc()->setBeginningBalance($this->c->fresh(), '100', null, $owner);
    }

    public function test_filament_form_visibility_and_save(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        Livewire::test(EditCustomer::class, ['record' => $this->c->id])
            ->assertFormFieldIsHidden('beginning_balance')
            ->fillForm(['beginning_balance' => 5000])->call('save');
        $this->assertSame('0.0000', $this->c->fresh()->beginning_balance);
        Livewire::test(ListCustomers::class)->assertTableColumnHidden('beginning_balance')->assertDontSee('Pay beginning bal.');

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(EditCustomer::class, ['record' => $this->c->id])
            ->assertFormFieldIsVisible('beginning_balance')
            ->fillForm(['beginning_balance' => '250.75', 'beginning_balance_date' => '2026-09-30', 'beginning_balance_note' => 'From QuickBooks'])
            ->call('save')->assertHasNoFormErrors();
        $c = $this->c->fresh();
        $this->assertSame('250.7500', $c->beginning_balance);
        $this->assertSame('2026-09-30', $c->beginning_balance_date->toDateString());
        $this->assertSame('250.7500', $this->bal('1100'));
        $this->assertSame('From QuickBooks', CustomerBalanceAudit::first()->note);
        Livewire::test(ListCustomers::class)->assertTableColumnExists('beginning_balance')->assertCanRenderTableColumn('beginning_balance')->assertSee('250.75');
    }
}
