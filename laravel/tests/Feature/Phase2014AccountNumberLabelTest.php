<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Imports\Types\CustomersImport;
use App\Imports\Types\OpeningBalancesImport;
use App\Imports\Types\PriceListsImport;
use App\Imports\Types\ShipTosImport;
use App\Livewire\CounterPos;
use App\Livewire\Driver\VanSale;
use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.14: customer Code field relabelled Account Number (column unchanged, still unique). */
class Phase2014AccountNumberLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    }

    public function test_customer_screens_show_account_number_label(): void
    {
        $c = Customer::where('code', 'CTEST-KY')->first() ?? Customer::first();
        $this->get(CustomerResource::getUrl('index'))->assertOk()->assertSee('Account Number');
        $this->get(CustomerResource::getUrl('edit', ['record' => $c]))->assertOk()->assertSee('Account Number');
        $this->get(CustomerResource::getUrl('create'))->assertOk()->assertSee('Account Number');

        Livewire::test(ListCustomers::class)->assertSee('Account Number');
        Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()])->assertSee('Account Number');
    }

    public function test_account_number_remains_unique(): void
    {
        $existing = Customer::where('code', 'CTEST-KY')->value('code') ?? Customer::value('code');
        $this->assertNotEmpty($existing);
        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'code' => $existing,
                'name' => 'Duplicate Account Number',
                'type' => 'retailer',
                'terms' => 'Net 15',
                'credit_limit' => 0,
                'active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
        $this->assertSame(1, Customer::where('code', $existing)->count());
    }

    public function test_import_and_export_labels_say_account_number(): void
    {
        $this->assertSame('Account Number', (new CustomersImport)->fields()['code']['label']);
        $this->assertSame('Account Number', (new ShipTosImport)->fields()['customer_code']['label']);
        $this->assertSame('Account Number', (new OpeningBalancesImport)->fields()['customer_code']['label']);
        $this->assertSame('Account Number (contract price)', (new PriceListsImport)->fields()['customer_code']['label']);
    }

    public function test_pos_and_van_pickers_show_account_number(): void
    {
        $c = Customer::factory()->create(['code' => 'A-991', 'name' => 'Acct Pick Test', 'active' => true, 'terms' => 'Net 15']);
        // POS and van sale use the searchable picker: the account number is searchable and shown in the result
        Livewire::test(\App\Livewire\CustomerPicker::class)->set('search', 'A-991')->assertSeeHtml('A-991')->assertSee('Acct Pick Test');

        Warehouse::firstOrCreate(['code' => 'VAN1'], ['name' => 'Van 1', 'active' => true, 'is_van' => true]);
        Livewire::test(VanSale::class)->assertSeeHtml('customer-picker');
    }
}
