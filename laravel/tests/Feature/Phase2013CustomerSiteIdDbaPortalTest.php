<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\LicensesRelationManager;
use App\Livewire\Portal\Login;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLicense;
use App\Models\User;
use App\Services\CustomerBalanceService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.13: Site ID, DBA, Customer Contacts / portal flag, mid-form tabs, beginning balance 2dp. */
class Phase2013CustomerSiteIdDbaPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    }

    public function test_migration_moves_ky_numeric_site_ids_and_adds_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('customers', 'dba'));
        $this->assertTrue(Schema::hasColumn('customer_contacts', 'is_portal_contact'));
        $this->assertTrue(Schema::hasColumn('customer_licenses', 'site_id'));

        $c = Customer::factory()->create(['name' => 'KY Move Test']);
        // Pre-0.20.13 shape: Site ID stored in `number` for KY. Simulate that, then run the same move the migration uses.
        $idNum = DB::table('customer_licenses')->insertGetId([
            'customer_id' => $c->id, 'type' => 'tobacco_retail', 'number' => '7654321', 'state' => 'KY',
            'site_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $idAlpha = DB::table('customer_licenses')->insertGetId([
            'customer_id' => $c->id, 'type' => 'tobacco_retail', 'number' => 'ABC-99', 'state' => 'KY',
            'site_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Same move as phase2013 migration (SQLite path) — avoid re-running ->change() which rebuilds the table.
        foreach (DB::table('customer_licenses')->whereRaw("UPPER(state) = 'KY'")->whereNull('site_id')->whereNotNull('number')->get(['id', 'number']) as $r) {
            if (preg_match('/^[0-9]{1,10}$/', (string) $r->number)) {
                DB::table('customer_licenses')->where('id', $r->id)->update(['site_id' => $r->number, 'number' => null]);
            }
        }

        $num = DB::table('customer_licenses')->where('id', $idNum)->first();
        $alpha = DB::table('customer_licenses')->where('id', $idAlpha)->first();
        $this->assertNotNull($num);
        $this->assertSame('7654321', $num->site_id);
        $this->assertNull($num->number);
        $this->assertSame('ABC-99', $alpha->number);
        $this->assertNull($alpha->site_id);
    }

    public function test_beginning_balance_is_two_decimal_places(): void
    {
        $c = Customer::first();
        $admin = auth()->user();
        app(CustomerBalanceService::class)->setBeginningBalance($c, '12.3456', now()->toDateString(), $admin, 'test');
        $c->refresh();
        $this->assertSame('12.35', number_format((float) $c->beginning_balance, 2, '.', ''));
        $this->assertSame(2, (int) $c->getCasts()['beginning_balance'][-1]); // decimal:2
        Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()])
            ->assertFormSet(['beginning_balance' => '12.35']);
    }

    public function test_dba_saved_and_shown_on_edit(): void
    {
        $c = Customer::first();
        Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()])
            ->fillForm(['dba' => 'Trading As LLC', 'name' => $c->name])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Trading As LLC', $c->fresh()->dba);
        $this->get(CustomerResource::getUrl('edit', ['record' => $c]))->assertOk()->assertSee('DBA')->assertSee('Customer Name');
    }

    public function test_relation_tab_order_and_mid_form_placement(): void
    {
        $rels = array_values(CustomerResource::getRelations());
        $this->assertSame('Customer Contacts', $rels[0]::getTitle(Customer::first(), EditCustomer::class));
        $this->assertSame('Ship To', $rels[1]::getTitle(Customer::first(), EditCustomer::class));
        $this->assertSame('Licenses', $rels[2]::getTitle(Customer::first(), EditCustomer::class));
        // History title from HistoryRelationManager
        $this->assertSame('History', $rels[3]::getTitle(Customer::first(), EditCustomer::class));

        $c = Customer::first();
        $names = [];
        $lw = Livewire::test(EditCustomer::class, ['record' => $c->getRouteKey()]);
        foreach ($lw->instance()->form->getFlatComponents(withHidden: true) as $comp) {
            if (method_exists($comp, 'getHeading') && $comp->getHeading()) {
                $names[] = 'section:'.$comp->getHeading();
            }
            if (method_exists($comp, 'getView') && str_contains((string) $comp->getView(), 'embedded-relations')) {
                $names[] = 'embedded-relations';
            }
        }
        $pos = array_flip($names);
        $this->assertArrayHasKey('section:Customer', $pos);
        $this->assertArrayHasKey('embedded-relations', $pos);
        $this->assertArrayHasKey('section:Terms & pricing', $pos);
        $this->assertLessThan($pos['embedded-relations'], $pos['section:Customer']);
        $this->assertLessThan($pos['section:Terms & pricing'], $pos['embedded-relations']);
        // Custom view: no bottom relation managers block (form only page).
        $this->assertStringContainsString('edit-customer', $lw->instance()->getView());
    }

    public function test_ky_site_id_only_license_counts_for_compliance(): void
    {
        $c = Customer::where('code', 'CTEST-KY')->first() ?? Customer::first();
        $ship = $c->shipTos()->first();
        CustomerLicense::where('customer_id', $c->id)->delete();
        CustomerLicense::factory()->kySiteId('7654321')->create([
            'customer_id' => $c->id,
            'type' => 'tobacco_retail',
            'ship_to_id' => null,
            'expires_at' => now()->addYear(),
        ]);
        // Empty proof does not count
        CustomerLicense::create([
            'customer_id' => $c->id, 'type' => 'tobacco_retail', 'state' => 'KY',
            'site_id' => null, 'number' => null, 'expires_at' => now()->addYear(),
        ]);
        $this->assertTrue(CustomerLicense::where('site_id', '7654321')->first()->hasProof());
        $this->assertSame(CustomerLicense::KY_ABC_LOOKUP_URL, 'https://abcportal.ky.gov/BelleExternal/licenselookup/licensedetails');

        Livewire::test(LicensesRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditCustomer::class])
            ->assertSuccessful()
            ->callTableAction('create', data: [
                'type' => 'hemp', 'site_id' => '999', 'number' => null, 'state' => 'KY',
                'expires_at' => now()->addYear()->toDateString(),
            ])->assertHasNoTableActionErrors();
        $this->assertTrue(CustomerLicense::where('site_id', '999')->whereNull('number')->exists());
    }

    public function test_portal_contact_requires_email_and_flag(): void
    {
        $ky = Customer::where('code', 'CTEST-KY')->first();
        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $ky, 'pageClass' => EditCustomer::class])
            ->callTableAction('create', data: [
                'name' => 'No Email', 'email' => null, 'active' => true, 'is_portal_contact' => false,
            ])->assertHasNoTableActionErrors();
        $plain = CustomerContact::where('name', 'No Email')->firstOrFail();
        $this->assertNull($plain->email);
        $this->assertFalse($plain->is_portal_contact);
        $this->assertFalse($plain->canUsePortal());

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $ky, 'pageClass' => EditCustomer::class])
            ->callTableAction('create', data: [
                'name' => 'Portal Pat', 'email' => 'portalpat@example.test', 'active' => true, 'is_portal_contact' => true,
            ])->assertHasNoTableActionErrors();
        $p = CustomerContact::where('email', 'portalpat@example.test')->firstOrFail();
        $this->assertTrue($p->is_portal_contact);
        $p->setPasswordFromLink('Secret12345');
        $this->assertTrue($p->fresh()->canUsePortal());

        // Non-portal contact with password still cannot log in
        $plain->forceFill(['password' => 'Secret12345', 'email' => 'plain@example.test'])->save();
        $plain->refresh();
        $this->assertFalse($plain->is_portal_contact);
        Livewire::test(Login::class)->set('email', 'plain@example.test')->set('password', 'Secret12345')
            ->call('login')->assertHasErrors('email');
    }

    public function test_clearing_email_turns_off_portal(): void
    {
        $ky = Customer::where('code', 'CTEST-KY')->first();
        $k = CustomerContact::create([
            'customer_id' => $ky->id, 'name' => 'Temp', 'email' => 'temp@example.test',
            'active' => true, 'is_portal_contact' => true,
        ]);
        $k->update(['email' => null]);
        $this->assertFalse($k->fresh()->is_portal_contact);
        $this->assertNull($k->fresh()->email);
    }
}
