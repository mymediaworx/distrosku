<?php

namespace Tests\Feature;

use App\Accounting\DunningService;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\LicensesRelationManager;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerLicense;
use App\Models\LicenseType;
use App\Models\User;
use App\Support\Terms;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.6: Edit Customer screen (terms, price group, EIN, field order, licenses) + responsive smoke. */
class Phase206CustomerScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    }

    private function fieldOrder(): array
    {
        $c = Customer::first();
        $names = [];
        $lw = Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()]);
        foreach ($lw->instance()->form->getFlatComponents(withHidden: true) as $comp) {
            $key = method_exists($comp, 'getName') ? $comp->getName() : (method_exists($comp, 'getHeading') ? 'section:'.$comp->getHeading() : null);
            if (! $key && method_exists($comp, 'getLabel')) {
                $key = 'set:'.$comp->getLabel();
            }
            if ($key) {
                $names[] = $key;
            }
        }

        return $names;
    }

    public function test_field_order_and_presence(): void
    {
        $o = $this->fieldOrder();
        foreach (['terms', 'state_tax_id', 'fein', 'customer_group_id', 'credit_limit', 'available_credit', 'beginning_balance', 'aging', 'msa_class_of_trade'] as $f) {
            $this->assertContains($f, $o, "$f missing");
        }
        $pos = array_flip($o);
        $this->assertLessThan($pos['customer_group_id'], $pos['state_tax_id']);
        $this->assertLessThan($pos['customer_group_id'], $pos['fein']);
        $this->assertLessThan($pos['fein'], $pos['state_tax_id']);
        $this->assertSame($pos['available_credit'] + 2, $pos['beginning_balance'], 'beginning balance right below available credit'); // +1 = fieldset
        $this->assertLessThan($pos['msa_class_of_trade'], $pos['aging'], 'MSA below balance & aging');
    }

    public function test_terms_dropdown_options_and_normalize(): void
    {
        $this->assertSame('Net 30', Terms::normalize('net30'));
        $this->assertSame('Net 15', Terms::normalize(' NET 15 days'));
        $this->assertSame('COD', Terms::normalize('c.o.d.'));
        $this->assertSame('2/10 Net 30', Terms::normalize('2/10 n30'));
        $this->assertNull(Terms::normalize('Special deal'));
        $this->assertArrayHasKey('Special deal', Terms::options('Special deal'));
        $this->assertArrayHasKey('Net 60', Terms::options());
        $this->assertSame(30, DunningService::termsDays('2/10 Net 30'));
        $this->assertSame(0, DunningService::termsDays('Due on receipt'));
    }

    public function test_price_group_options_and_federal_ein_save(): void
    {
        $names = CustomerGroup::pluck('name')->all();
        $this->assertContains('Standard', $names);
        $this->assertContains('Wholesale', $names);
        CustomerGroup::create(['code' => 'VIP', 'name' => 'VIP']);
        $c = Customer::first();
        $c->update(['terms' => 'Odd legacy']);
        $wh = CustomerGroup::where('name', 'Wholesale')->first();
        Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()])
            ->assertFormFieldExists('customer_group_id')
            ->assertFormSet(['terms' => 'Odd legacy'])
            ->fillForm(['fein' => '123456789', 'customer_group_id' => $wh->id, 'terms' => 'Net 30'])
            ->call('save')->assertHasNoFormErrors();
        $c->refresh();
        $this->assertSame('12-3456789', $c->fein);
        $this->assertSame($wh->id, $c->customer_group_id);
        $this->assertSame('Net 30', $c->terms);
        Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()])
            ->fillForm(['fein' => 'abc'])->call('save')->assertHasFormErrors(['fein']);
        $this->get('/admin/customer-groups')->assertOk()->assertSee('VIP');
    }

    public function test_license_types_settings_and_relation_manager(): void
    {
        $this->assertSame('KY Tobacco License', LicenseType::label('tobacco_retail'));
        $this->assertSame('KY Hemp License', LicenseType::label('hemp'));
        $this->get('/admin/settings/license-types')->assertOk()->assertSee('KY Tobacco License')->assertSee('KY Hemp License');
        LicenseType::create(['code' => 'ky_kratom', 'name' => 'KY Kratom License', 'state' => 'KY']);
        $this->assertArrayHasKey('ky_kratom', LicenseType::options());
        $c = Customer::first();
        Livewire::test(LicensesRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditCustomer::class])
            ->assertSuccessful()
            ->callTableAction('create', data: ['type' => 'hemp', 'number' => 'H-1', 'state' => 'ky', 'expires_at' => now()->addYear()->toDateString()])
            ->assertHasNoTableActionErrors();
        $this->assertTrue(CustomerLicense::where('customer_id', $c->id)->where('type', 'hemp')->where('state', 'KY')->exists());
        $this->get(CustomerResource::getUrl('edit', ['record' => $c]))->assertOk()->assertSee('Licenses');
        $this->get('/admin/customer-licenses')->assertOk();
        // unknown existing type stays visible
        $this->assertArrayHasKey('legacy_x', LicenseType::options('legacy_x'));
    }

    public function test_key_pages_render(): void
    {
        foreach (['/admin', '/admin/customers', '/admin/customers/create', '/admin/sales-orders', '/admin/products', '/admin/purchase-orders',
            '/admin/sales/counter-pickup', '/admin/settings/license-types', '/admin/compliance-rule-versions/create'] as $u) {
            $this->get($u)->assertOk();
        }
        $this->get('/admin/customers')->assertSee('admin-polish', false)->assertDontSee('xx-never');
    }

    public function test_migration_is_rerunnable_and_maps_data(): void
    {
        $c = Customer::first();
        $c->update(['terms' => 'NET30']);
        CustomerGroup::where('name', 'Wholesale')->update(['name' => 'wholesale ']);
        \DB::table('customer_licenses')->insert(['customer_id' => $c->id, 'type' => 'old_weird', 'number' => 'X', 'state' => 'KY', 'created_at' => now(), 'updated_at' => now()]);
        $m = require database_path('migrations/2026_11_20_000001_phase206_customer_screen.php');
        $m->up();
        $m->up();
        $this->assertSame('Net 30', $c->fresh()->terms);
        $this->assertSame(1, CustomerGroup::where('name', 'Wholesale')->count());
        $this->assertSame(1, CustomerGroup::where('name', 'Standard')->count());
        $this->assertSame(1, LicenseType::where('code', 'old_weird')->count());
        $this->assertSame(1, LicenseType::where('code', 'tobacco_retail')->count());
    }
}
