<?php

namespace Tests\Feature;

use App\Filament\Pages\MarketingSettingsPage;
use App\Marketing\MarketingSync;
use App\Models\Customer;
use App\Models\GhlSyncLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class Phase192MarketingOneWayTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Http::preventStrayRequests();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->c = Customer::where('code', 'CTEST-KY')->first();
        $this->c->updateQuietly(['email' => 'buyer@example.com', 'state_tax_id' => 'KY-123456', 'email_opt_in' => true, 'sms_opt_in' => false]);
        $this->c->licenses()->create(['type' => 'resale', 'number' => 'RS-9988', 'state' => 'KY', 'expires_at' => now()->addYear()]);
        Setting::put('mkt_token', 'pit-test-fake');
        Setting::put('mkt_location_id', 'LOC123');
        Setting::put('mkt_enabled', '1');
    }

    private function fake(string $resaleType = 'TEXT', bool $new = true): void
    {
        Http::fake([
            'services.leadconnectorhq.com/locations/LOC123/customFields*' => Http::response(['customFields' => [
                ['id' => 'F_ACC', 'fieldKey' => 'contact.vega_account_number', 'name' => 'Vega Account Number', 'dataType' => 'TEXT'],
                ['id' => 'F_TAX', 'fieldKey' => 'contact.state_tax_id_number', 'name' => 'State Tax ID Number', 'dataType' => 'TEXT'],
                ['id' => 'F_RES', 'fieldKey' => 'contact.resale_sales_tax_certificate', 'name' => 'Resale Sales Tax Certificate', 'dataType' => $resaleType],
            ]]),
            'services.leadconnectorhq.com/contacts/upsert' => Http::response(['new' => $new, 'contact' => ['id' => 'CON9']]),
            'services.leadconnectorhq.com/contacts/CON9/tags' => Http::response(['tags' => []]),
        ]);
    }

    private function upsert(): array
    {
        $d = null;
        Http::assertSent(function (Request $q) use (&$d) {
            if (str_ends_with($q->url(), '/contacts/upsert')) {
                $d = $q->data();
            }

            return true;
        });

        return $d;
    }

    public function test_custom_fields_mapped_by_key_at_runtime(): void
    {
        $this->fake();
        app(MarketingSync::class)->syncContact($this->c);
        $f = collect($this->upsert()['customFields'])->pluck('field_value', 'id')->all();
        $this->assertSame(['F_ACC' => 'CTEST-KY', 'F_TAX' => 'KY-123456', 'F_RES' => 'RS-9988'], $f);
        $this->assertTrue($this->c->fresh()->ghl_created_by_erp);
    }

    public function test_file_upload_field_skipped_with_note_and_blank_never_sent(): void
    {
        $this->fake('FILE_UPLOAD');
        $this->c->updateQuietly(['state_tax_id' => null]);
        $s = app(MarketingSync::class);
        $s->syncContact($this->c);
        $this->assertSame(['F_ACC'], collect($this->upsert()['customFields'])->pluck('id')->all());
        $this->assertStringContainsString('FILE_UPLOAD', implode(' ', $s->lastNotes));
    }

    public function test_mapping_setting_and_dash_disables(): void
    {
        $this->fake();
        Setting::put('mkt_cf_account', 'F_TAX'); // by id
        Setting::put('mkt_cf_state_tax', '-');
        Setting::put('mkt_cf_resale', '-');
        app(MarketingSync::class)->syncContact($this->c);
        $this->assertSame([['id' => 'F_TAX', 'field_value' => 'CTEST-KY']], $this->upsert()['customFields']);
    }

    public function test_consent_driven_by_erp_without_wiping_foreign_dnd(): void
    {
        $this->fake(new: false);
        app(MarketingSync::class)->syncContact($this->c); // matched an existing contact
        $d = $this->upsert()['dndSettings'];
        $this->assertSame('active', $d['SMS']['status']);
        $this->assertArrayNotHasKey('Email', $d);
        $c = $this->c->fresh();
        $this->assertFalse($c->ghl_created_by_erp);
        $c->sms_opt_in = true;
        $this->assertArrayNotHasKey('dndSettings', MarketingSync::contactPayload($c)); // never cleared on a pre-existing contact
        $c->ghl_created_by_erp = true;
        $p = MarketingSync::contactPayload($c);
        $this->assertSame('inactive', $p['dndSettings']['SMS']['status']); // our own contact: opt-in clears it
        $this->assertSame('inactive', $p['dndSettings']['Email']['status']);
    }

    public function test_inbound_removed(): void
    {
        $this->post('/hooks/marketing', ['contactId' => 'X'])->assertNotFound();
        $this->assertFalse(Schema::hasTable('marketing_leads'));
        $this->assertArrayNotHasKey('marketing:pull', \Illuminate\Support\Facades\Artisan::all());
        $this->assertFalse(class_exists(\App\Marketing\MarketingInbound::class));
        $m = include database_path('migrations/2026_11_13_000001_phase192_marketing_oneway.php');
        $m->up(); // re-runnable
        $this->assertTrue(Schema::hasColumn('customers', 'state_tax_id'));
    }

    public function test_nightly_only_new_customers_and_edits_sync_immediately(): void
    {
        $this->fake();
        Customer::where('active', true)->where('id', '!=', $this->c->id)->update(['ghl_contact_id' => 'LINKED']);
        $this->c->updateQuietly(['ghl_contact_id' => null]);
        Queue::fake();
        $this->artisan('marketing:reconcile')->expectsOutputToContain('Queued 1 new customer(s)')->assertExitCode(0);
        Queue::assertPushed(\App\Marketing\Jobs\MarketingJob::class, 1);
        $other = Customer::where('ghl_contact_id', 'LINKED')->first();
        $other->update(['phone' => '+15555550111']);
        Queue::assertPushed(\App\Marketing\Jobs\MarketingJob::class, 2);
    }

    public function test_push_all_throttled_admin_only(): void
    {
        Queue::fake();
        for ($i = 0; $i < 25; $i++) {
            Customer::create(['code' => "BULK{$i}", 'name' => "Bulk {$i}", 'type' => 'tobacco_retail', 'email' => "b{$i}@example.com", 'active' => true]);
        }
        Queue::fake(); // reset: creating customers queued their own syncs
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        Livewire::test(MarketingSettingsPage::class)->callAction('pushAll')->assertNotified();
        $jobs = Queue::pushed(\App\Marketing\Jobs\MarketingJob::class);
        $this->assertSame(Customer::where('active', true)->count(), $jobs->count());
        $this->assertGreaterThan(0, $jobs->filter(fn ($j) => $j->delay !== null)->count()); // spread out
        $this->assertSame(MarketingSync::BULK_PER_10S, $jobs->filter(fn ($j) => $j->delay === null)->count());
        $this->assertTrue(GhlSyncLog::count() >= $jobs->count());
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        $this->get('/admin/brandworx-marketing')->assertForbidden();
    }
}
