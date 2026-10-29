<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Pages\CardPayments;
use App\Filament\Pages\RulesHub;
use App\Filament\Pages\Settings;
use App\Models\Account;
use App\Models\ComplianceRuleVersion;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RulesSync;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\RulesHub\RulesHubClient;
use App\RulesHub\RulesPackage;
use App\Services\PaymentService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentsRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    private function keys(): void
    {
        Setting::put('authnet_login_id', 'sandboxLogin');
        Setting::put('authnet_transaction_key', 'sandboxTxKey');
        Setting::put('authnet_client_key', 'sandboxClient');
    }

    private function bom(array $a)
    {
        return Http::response("\xEF\xBB\xBF".json_encode($a), 200);
    }

    private function ok(array $extra = []): array
    {
        return $extra + ['messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]]];
    }

    private function invoice(string $price = '100'): SalesOrder
    {
        $wh = Warehouse::where('code', 'MAIN')->first();
        $p = Product::factory()->create(['tax_class' => 'general', 'price' => $price]);
        DB::table('inventories')->insert(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'qty' => 10, 'reserved' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_price' => $price]);
        $svc = app(SalesOrderService::class);
        $svc->confirm($so);
        $svc->deliver($so);
        $svc->invoice($so->fresh());

        return $so->fresh();
    }

    private function bal(string $code): string
    {
        $id = Account::where('code', $code)->value('id');

        return bcsub((string) JournalLine::where('account_id', $id)->sum('debit'), (string) JournalLine::where('account_id', $id)->sum('credit'), 4);
    }

    public function test_not_configured_and_non_sandbox_refused(): void
    {
        $svc = app(PaymentService::class);
        $this->assertFalse($svc->gateway()->isConfigured());
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        Livewire::test(CardPayments::class)->assertSee('not configured');
        $this->keys();
        config(['erp.authorizenet.environment' => 'production']);
        $this->assertStringContainsString('sandbox', app(PaymentService::class)->gateway()->configurationProblem());
        Http::fake();
        $this->expectException(WorkflowException::class);
        app(PaymentService::class)->addMethod(Customer::factory()->create(), 'COMMON.ACCEPT.INAPP.PAYMENT', 'tok', 'card', 'Visa');
    }

    public function test_store_token_charge_refund_void_with_http_fakes(): void
    {
        $this->keys();
        Http::fakeSequence('apitest.authorize.net/*')
            ->push("\xEF\xBB\xBF".json_encode($this->ok(['customerProfileId' => '900', 'customerPaymentProfileIdList' => ['901']])))
            ->push("\xEF\xBB\xBF".json_encode($this->ok(['transactionResponse' => ['responseCode' => '1', 'transId' => '6001', 'messages' => [['description' => 'Approved']]]])))
            ->push("\xEF\xBB\xBF".json_encode($this->ok(['transactionResponse' => ['responseCode' => '1', 'transId' => '6002']])))
            ->push("\xEF\xBB\xBF".json_encode($this->ok(['transactionResponse' => ['responseCode' => '1', 'transId' => '6003']])))
            ->push("\xEF\xBB\xBF".json_encode($this->ok(['transactionResponse' => ['responseCode' => '1', 'transId' => '6003']])));
        $so = $this->invoice('100');
        $svc = app(PaymentService::class);
        $pm = $svc->addMethod($so->customer, 'COMMON.ACCEPT.INAPP.PAYMENT', 'opaque-token', 'card', 'Visa 4111111111111111');
        $this->assertSame('900', $pm->customer_profile_id);
        $this->assertStringNotContainsString('4111111111111111', $pm->label);
        $p = $svc->chargeInvoice($so, $pm, '60');
        $this->assertSame('6001', $p->provider_transaction_id);
        $this->assertSame('40.0000', $so->fresh()->balance());
        $refund = $svc->refund($p, '10');
        $this->assertSame('refund', $refund->kind);
        $this->assertSame('50.0000', $so->fresh()->balance());
        $this->assertSame('50.0000', $this->bal('1000'));

        $p2 = $svc->chargeInvoice($so->fresh(), $pm, '50');
        $svc->void($p2);
        $this->assertSame('voided', $p2->fresh()->status);
        $this->assertSame('50.0000', $so->fresh()->balance());
        $this->assertSame('50.0000', $this->bal('1000'));
        $this->assertSame(5, PaymentTransaction::count());
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://apitest.authorize.net/')
            && ($r['createTransactionRequest']['transactionRequest']['amount'] ?? null) === '60.00' || isset($r['createCustomerProfileRequest']) || isset($r['createTransactionRequest']));
        // no card data in any stored row
        $this->assertStringNotContainsString('4111', json_encode(PaymentTransaction::all()->toArray()));
    }

    public function test_decline_does_not_post(): void
    {
        $this->keys();
        Http::fake(['apitest.authorize.net/*' => $this->bom(['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00027', 'text' => 'The transaction was unsuccessful.']]],
            'transactionResponse' => ['responseCode' => '2', 'transId' => '0', 'errors' => [['errorCode' => '2', 'errorText' => 'This transaction has been declined.']]]])]);
        $so = $this->invoice();
        $pm = $so->customer->paymentMethods()->create(['provider' => 'authorizenet', 'type' => 'card', 'label' => 'Visa ••1111', 'customer_profile_id' => '1', 'payment_profile_id' => '2']);
        try {
            app(PaymentService::class)->chargeInvoice($so, $pm);
            $this->fail('no exception');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('declined', $e->getMessage());
        }
        $this->assertSame('100.0000', $so->fresh()->balance());
        $this->assertFalse(PaymentTransaction::first()->success);
    }

    public function test_settings_encrypted_and_owner_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        // 0.20.17: Company Settings opens for admins too, but the gateway / instance-manager / Rules Hub secrets stay owner-only
        $this->get('/admin/settings')->assertOk();
        Livewire::test(Settings::class)->assertSee('Company Settings')->assertDontSee('Transaction Key')->assertDontSee('Shared secret');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Settings::class)->fillForm(['authnet_login_id' => 'LOGIN123', 'authnet_transaction_key' => 'SECRETKEY', 'ky_filer_fein' => '123456789'])->call('save');
        $raw = DB::table('settings')->where('key', 'authnet_transaction_key')->value('value');
        $this->assertNotSame('SECRETKEY', $raw);
        $this->assertStringNotContainsString('SECRETKEY', $raw);
        $this->assertSame('SECRETKEY', Setting::get('authnet_transaction_key'));
        Livewire::test(Settings::class)->assertDontSee('SECRETKEY')->fillForm(['authnet_transaction_key' => ''])->call('save');
        $this->assertSame('SECRETKEY', Setting::get('authnet_transaction_key')); // blank keeps
        $this->actingAs(User::factory()->create(['role' => 'brandworx_admin']));
        $this->get('/admin/settings')->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/admin/rules-hub')->assertForbidden();
        $this->get('/admin/card-payments')->assertOk();
        $this->get('/admin/credit-memos')->assertOk();
    }

    private function package(string $secret, string $version, array $over = []): string
    {
        return RulesPackage::sign(['format' => 'vega-rules/1', 'version' => $version,
            'rules' => [['id' => 'r-ky-1', 'jurisdiction' => 'ky', 'tax_class' => 'vapor', 'rule' => 'license_required', 'license_type' => 'tobacco_retail', 'effective_from' => '2026-01-01']],
            'tax_rates' => [['id' => 't-ky-1', 'jurisdiction' => 'KY', 'tax_type' => 'hub_test_rate', 'rate' => '0.5', 'basis' => 'percent', 'effective_from' => '2026-01-01']],
        ] + $over, $secret);
    }

    private function tamper(string $signed): string
    {
        $e = json_decode($signed, true);
        $p = json_decode(base64_decode($e['payload']), true);
        $p['tax_rates'][0]['rate'] = '0.01';
        $e['payload'] = base64_encode(json_encode($p));

        return json_encode($e);
    }

    public function test_rules_sync_verifies_signature_and_version(): void
    {
        $kp = RulesPackage::keypair();
        Setting::put('rules_hub_url', 'https://hub.example.test/rules.json');
        Setting::put('rules_hub_public_key', $kp['public']);
        Http::fakeSequence('hub.example.test/*')->push($this->package($kp['secret'], '1.0.0'))->push($this->package($kp['secret'], '1.0.0'))
            ->push($this->tamper($this->package($kp['secret'], '2.0.0')))
            ->push($this->package(RulesPackage::keypair()['secret'], '3.0.0'));
        $c = app(RulesHubClient::class);
        $s = $c->sync('manual');
        $this->assertSame('ok', $s->status, $s->message);
        $this->assertSame(1, ComplianceRuleVersion::where('hub_id', 'r-ky-1')->count());
        $this->assertSame(1, TaxRate::where('hub_id', 't-ky-1')->count());
        $this->assertSame('1.0.0', $c->currentVersion());
        $this->assertSame('unchanged', $c->sync()->status);
        $this->assertSame('failed', $c->sync()->status);   // tampered
        $this->assertSame('failed', $c->sync()->status);   // wrong key
        $this->assertSame('1.0.0', $c->currentVersion());
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET' && array_keys($q) === ['install_id', 'app_version'];
        });

        $this->actingAs(User::factory()->create(['role' => 'brandworx_admin']));
        Livewire::test(RulesHub::class)->assertSee('1.0.0')->assertCanSeeTableRecords(RulesSync::all());
    }

    public function test_rules_sync_without_url_and_build_cli(): void
    {
        $this->assertSame('failed', app(RulesHubClient::class)->sync()->status);
        $dir = sys_get_temp_dir().'/rh'.uniqid();
        mkdir($dir);
        $this->artisan('rules:keygen', ['--out' => "$dir/secret.key"])->assertExitCode(0);
        file_put_contents("$dir/in.json", json_encode(['format' => 'vega-rules/1', 'version' => '1.2.3', 'rules' => [], 'tax_rates' => []]));
        $this->artisan('rules:build', ['input' => "$dir/in.json", '--sign' => "$dir/secret.key", '--out' => "$dir/out.json"])->assertExitCode(0);
        $env = json_decode(file_get_contents("$dir/out.json"), true);
        $this->assertSame('vega-rules-signed/1', $env['format']);
        $pub = base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode(file_get_contents("$dir/secret.key"))));
        $this->assertSame('1.2.3', RulesPackage::verify(file_get_contents("$dir/out.json"), $pub)['version']);
        $this->artisan('rules:build', ['input' => "$dir/in.json"])->assertExitCode(1);
    }

    public function test_new_pages_render_for_owner(): void
    {
        $this->invoice();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        foreach (['/admin/credit-memos', '/admin/card-payments', '/admin/rules-hub', '/admin/settings', '/admin/sales-orders', '/admin/payments', '/admin/customers',
            '/admin/tax-filings', '/admin/products/create', '/admin/reports/ky-subjobber'] as $u) {
            $this->get($u)->assertOk();
        }
        $this->keys();
        $so = SalesOrder::first();
        $this->get('/admin/card-payments?order='.$so->id)->assertOk()->assertSee('jstest.authorize.net', false)->assertDontSee('name="cardNumber"', false);
    }
}
