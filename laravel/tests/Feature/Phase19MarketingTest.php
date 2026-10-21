<?php

namespace Tests\Feature;

use App\Filament\Pages\MarketingSettingsPage;
use App\Filament\Resources\GhlSyncLogResource;
use App\Marketing\GhlClient;
use App\Marketing\MarketingEvents;
use App\Marketing\MarketingSettings;
use App\Models\Customer;
use App\Models\GhlSyncLog;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\TransactionalTemplate;
use App\Models\User;
use App\Models\Warehouse;
use App\Notify\NullNotifier;
use App\Notify\TransactionalNotifier;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class Phase19MarketingTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_TOKEN = 'pit-test-0000-fake-token-abcd';

    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Http::preventStrayRequests();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->c = Customer::where('code', 'CTEST-KY')->first();
        $this->c->update(['email' => 'buyer@example.com', 'phone' => '+15555550100']);
    }

    private function enable(bool $notify = false, ?string $webhook = null): void
    {
        Setting::put('mkt_token', self::FAKE_TOKEN);
        Setting::put('mkt_location_id', 'LOC123');
        Setting::put('mkt_enabled', '1');
        Setting::put('mkt_notify_enabled', $notify ? '1' : '0');
        Setting::put('mkt_webhook_url', $webhook);
    }

    private function fakeApi(): void
    {
        Http::fake([
            'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'CON1']]),
            'services.leadconnectorhq.com/locations/*/customFields*' => Http::response(['customFields' => []]),
            'services.leadconnectorhq.com/contacts/CON1/tags' => Http::response(['tags' => []]),
            'services.leadconnectorhq.com/conversations/messages' => Http::response(['messageId' => 'MSG1']),
            'hooks.example.com/*' => Http::response(['ok' => true]),
        ]);
    }

    private function order(): SalesOrder
    {
        $so = SalesOrder::create(['customer_id' => $this->c->id, 'ship_to_id' => $this->c->shipTos()->value('id'), 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'qty' => 1]);

        return $so->fresh();
    }

    public function test_off_by_default_hidden_and_no_calls(): void
    {
        $this->assertFalse(MarketingSettings::enabled());
        $this->assertInstanceOf(NullNotifier::class, app(TransactionalNotifier::class));
        $this->c->update(['name' => 'Changed']);
        app(SalesOrderService::class)->confirm($this->order());
        Http::assertNothingSent();
        $this->assertSame(0, GhlSyncLog::count());
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        $this->assertFalse(GhlSyncLogResource::canViewAny());
        // enabled without a token stays off
        Setting::put('mkt_enabled', '1');
        $this->assertFalse(MarketingSettings::enabled());
    }

    public function test_settings_token_encrypted_masked_never_shown(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        Livewire::test(MarketingSettingsPage::class)->set('data.token', self::FAKE_TOKEN)->set('data.location_id', 'LOC123')
            ->set('data.enabled', true)->call('save')->assertHasNoErrors()->assertDontSee(self::FAKE_TOKEN);
        $raw = DB::table('settings')->where('key', 'mkt_token')->value('value');
        $this->assertNotSame(self::FAKE_TOKEN, $raw);
        $this->assertStringNotContainsString(self::FAKE_TOKEN, (string) $raw);
        $this->assertSame(self::FAKE_TOKEN, MarketingSettings::token());
        $this->assertSame('Set (ends …abcd)', MarketingSettings::maskedToken());
        $this->assertTrue(MarketingSettings::enabled());
        $this->assertSame('https://app.brandworx.ai', MarketingSettings::appUrl());
        $this->assertSame('https://app.brandworx.ai/v2/location/LOC123/contacts/detail/CON1', MarketingSettings::contactUrl('CON1'));
        $this->get('/admin/brandworx-marketing')->assertOk()->assertSee('BrandWorx Marketing')->assertDontSee(self::FAKE_TOKEN)->assertDontSee('HighLevel');
        // blank token on save keeps the old one
        Livewire::test(MarketingSettingsPage::class)->set('data.location_id', 'LOC123')->call('save');
        $this->assertSame(self::FAKE_TOKEN, MarketingSettings::token());
        $this->assertStringNotContainsString(self::FAKE_TOKEN, \App\Models\ActivityLog::query()->get()->toJson());
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
        $this->get('/admin/brandworx-marketing')->assertForbidden();
    }

    public function test_connection_probe_is_read_only(): void
    {
        $this->enable();
        Http::fake(['services.leadconnectorhq.com/locations/LOC123' => Http::response(['location' => ['name' => 'Vega Test']]),
            'services.leadconnectorhq.com/*' => Http::response(['message' => 'The token is not authorized for this scope.'], 401)]);
        $r = app(GhlClient::class)->test();
        $this->assertTrue($r['location']['ok']);
        $this->assertSame('Vega Test', $r['location']['note']);
        $this->assertFalse($r['workflows']['ok']);
        Http::assertSent(fn (Request $q) => $q->hasHeader('Version', '2021-07-28') && $q->hasHeader('Authorization', 'Bearer '.self::FAKE_TOKEN));
        Http::assertNotSent(fn (Request $q) => $q->method() !== 'GET');
    }

    public function test_customer_sync_payload_tags_and_dnd(): void
    {
        $this->enable();
        $this->fakeApi();
        $this->c->update(['sms_opt_in' => false, 'email_opt_in' => true]);
        $this->assertSame('CON1', $this->c->fresh()->ghl_contact_id);
        Http::assertSent(function (Request $q) {
            if (! str_ends_with($q->url(), '/contacts/upsert')) {
                return false;
            }
            $d = $q->data();

            return $d['locationId'] === 'LOC123' && ! isset($d['tags']) // tags are added separately, never replaced
                && $d['dndSettings']['SMS']['status'] === 'active' && ! isset($d['dndSettings']['Email']); // never clear DND on a possibly pre-existing contact
        });
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/contacts/CON1/tags') && $q->method() === 'POST'
            && in_array('vega', $q->data()['tags'], true) && in_array('state:ky', $q->data()['tags'], true));
        // 0.19.2 one-way: linked + opt-in off still sets DND; opt-in on does not clear DND on a contact the ERP did not create
        $p = \App\Marketing\MarketingSync::contactPayload($this->c->fresh());
        $this->assertSame('active', $p['dndSettings']['SMS']['status']);
        $this->assertArrayNotHasKey('Email', $p['dndSettings']);
        $this->assertSame('ok', GhlSyncLog::where('kind', 'contact')->latest('id')->value('status'));
    }

    public function test_order_events_tag_and_webhook_once(): void
    {
        $this->enable(false, 'https://hooks.example.com/abc');
        $this->fakeApi();
        $so = $this->order();
        app(SalesOrderService::class)->confirm($so);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/contacts/CON1/tags') && $q->method() === 'POST' && $q->data()['tags'] === ['erp-order-placed']);
        Http::assertSent(fn (Request $q) => str_contains($q->url(), 'hooks.example.com') && $q->data()['event'] === 'order_placed' && $q->data()['order'] === $so->number);
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/conversations/messages'));
        $this->assertSame(1, GhlSyncLog::where('event', 'order_placed')->where('status', 'ok')->count());
        $this->assertNotNull(GhlSyncLog::where('dedupe_key', "event:order_placed:{$so->id}")->first());
    }

    public function test_invoice_due_and_overdue_events(): void
    {
        $this->enable();
        $this->fakeApi();
        $so = $this->order();
        SalesOrder::whereKey($so->id)->update(['status' => 'invoiced', 'invoiced_at' => now()->subDays(40), 'total' => 10]);
        $so = $so->fresh();
        if ((float) $so->balance() <= 0) {
            $this->markTestSkipped('demo order has no open balance');
        }
        $this->assertSame(1, MarketingEvents::invoiceDates());
        $this->assertSame(1, GhlSyncLog::where('event', 'invoice_overdue')->count());
        MarketingEvents::invoiceDates(); // once per invoice
        $this->assertSame(1, GhlSyncLog::where('event', 'invoice_overdue')->count());
    }

    public function test_notifier_off_then_only_opted_in_and_enabled_templates(): void
    {
        $this->enable(false);
        $this->fakeApi();
        app(SalesOrderService::class)->confirm($this->order());
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/conversations/messages'));

        $this->enable(true);
        $r = app(TransactionalNotifier::class)->notify('order_confirmed', $this->c, ['order' => 'SO-X', 'total' => '$1.00']);
        $this->assertSame(['email' => 'skipped: template off', 'sms' => 'skipped: template off'], $r);
        TransactionalTemplate::where('key', 'order_confirmed')->update(['enabled' => true]);
        $r = app(TransactionalNotifier::class)->notify('order_confirmed', $this->c->fresh(), ['order' => 'SO-Y', 'total' => '$1.00']);
        $this->assertSame(['email' => 'skipped: no email opt-in', 'sms' => 'skipped: no SMS opt-in'], $r);
        $this->c->update(['email_opt_in' => true]);
        $r = app(TransactionalNotifier::class)->notify('order_confirmed', $this->c->fresh(), ['order' => 'SO-Z', 'total' => '$1.00']);
        $this->assertSame('queued', $r['email']);
        Http::assertSent(fn (Request $q) => str_contains($q->url(), '/conversations/messages') && $q->data()['type'] === 'Email' && str_contains($q->data()['subject'], 'SO-Z'));
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/conversations/messages') && $q->data()['type'] === 'SMS');
    }

    public function test_failure_logged_not_retried_on_401(): void
    {
        $this->enable();
        Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'Invalid JWT'], 401)]);
        $this->c->update(['name' => 'Retry Co']);
        $log = GhlSyncLog::where('kind', 'contact')->latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertSame(401, $log->http_status);
        $this->assertStringNotContainsString(self::FAKE_TOKEN, (string) $log->error);
    }
}
