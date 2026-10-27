<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\ContactsRelationManager;
use App\Livewire\Portal\Catalog;
use App\Livewire\Portal\Login;
use App\Livewire\Portal\Orders;
use App\Livewire\Portal\Pay;
use App\Livewire\Portal\SetPassword;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Warehouse;
use App\Payments\GatewayResult;
use App\Payments\PaymentGateway;
use App\Portal\PortalService;
use App\Services\CustomerBalanceService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
    }

    private function contact(string $code = 'CTEST-KY', array $attrs = []): CustomerContact
    {
        $c = Customer::where('code', $code)->first();
        $k = CustomerContact::create($attrs + ['customer_id' => $c->id, 'name' => 'Pat Buyer', 'email' => strtolower($code).'@example.test', 'is_portal_contact' => true]);
        $k->setPasswordFromLink('Secret12345');

        return $k->fresh();
    }

    private function invoiced(Customer $c, string $sku = 'TST-GEN-001', string $qty = '2'): SalesOrder
    {
        $svc = app(SalesOrderService::class);
        $so = SalesOrder::create(['customer_id' => $c->id, 'ship_to_id' => $c->shipTos()->value('id'), 'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'),
            'order_date' => now()->toDateString()]);
        $p = Product::where('sku', $sku)->first();
        $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $p->price]);
        $svc->confirm($so->fresh());
        $svc->deliver($so->fresh());

        return $svc->invoice($so->fresh())->fresh();
    }

    /** Sign a contact into the portal guard without making it the default guard (as in a real request). */
    private function portal(CustomerContact $k): void
    {
        Auth::guard('portal')->login($k);
    }

    private function fakeGateway(): void
    {
        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public array $charges = [];

            public function name(): string
            {
                return 'authorizenet';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function configurationProblem(): ?string
            {
                return null;
            }

            public function clientConfig(): array
            {
                return ['apiLoginId' => 'x', 'clientKey' => 'y', 'acceptJsUrl' => 'https://jstest.authorize.net/v1/Accept.js', 'environment' => 'sandbox'];
            }

            public function storeToken(Customer $customer, string $descriptor, string $value, ?string $existingCustomerProfileId = null): GatewayResult
            {
                return new GatewayResult(true, null, 'I00001', 'ok', ['customer_profile_id' => 'CP1', 'payment_profile_id' => 'PP'.random_int(1, 9999)]);
            }

            public function charge(string $customerProfileId, string $paymentProfileId, string $amount, string $invoiceNumber): GatewayResult
            {
                $this->charges[] = [$invoiceNumber, $amount];

                return new GatewayResult(true, 'T'.count($this->charges), '1', 'Approved');
            }

            public function refund(string $c, string $p, string $t, string $a): GatewayResult
            {
                return new GatewayResult(true, 'R1');
            }

            public function void(string $t): GatewayResult
            {
                return new GatewayResult(true, 'V1');
            }
        });
    }

    public function test_admin_creates_contact_and_one_time_set_password_link_then_login(): void
    {
        $admin = User::factory()->create(['role' => 'owner']);
        $this->actingAs($admin);
        $ky = Customer::where('code', 'CTEST-KY')->first();
        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $ky, 'pageClass' => EditCustomer::class])
            ->callTableAction('create', data: ['name' => 'Jo Store', 'email' => 'JO@Example.test', 'active' => true, 'is_portal_contact' => true])->assertHasNoTableActionErrors();
        $k = CustomerContact::where('email', 'jo@example.test')->firstOrFail();
        $this->assertNull($k->password);
        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $ky, 'pageClass' => EditCustomer::class])
            ->callTableAction('link', $k)->assertNotified();
        $this->assertNotNull($k->fresh()->setup_token_hash);

        $url = $k->issueSetupLink(); // a new link invalidates the previous one
        $token = basename($url);
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Set your password');
        Livewire::test(SetPassword::class, ['token' => $token])->set('password', 'short')->set('password_confirmation', 'short')->call('save')->assertHasErrors('password');
        Livewire::test(SetPassword::class, ['token' => $token])->set('password', 'Goodpass123')->set('password_confirmation', 'Goodpass123')
            ->call('save')->assertRedirect(route('portal.catalog'));
        $this->assertAuthenticatedAs($k->fresh(), 'portal');
        $this->assertFalse(auth('web')->check());
        $this->get($url)->assertOk()->assertSee('Link expired'); // one-time
        $this->get('/admin')->assertRedirect(); // a portal session is not a staff session

        auth('portal')->logout();
        Livewire::test(Login::class)->set('email', 'jo@example.test')->set('password', 'Goodpass123')->call('login')->assertRedirect(route('portal.catalog'));
        $this->assertNotNull($k->fresh()->last_login_at);
    }

    public function test_login_rate_limited_and_expired_link_rejected(): void
    {
        $k = $this->contact();
        foreach (range(1, 5) as $i) {
            Livewire::test(Login::class)->set('email', $k->email)->set('password', 'wrong-pass')->call('login')->assertHasErrors('email');
        }
        Livewire::test(Login::class)->set('email', $k->email)->set('password', 'Secret12345')->call('login')->assertSee('Too many attempts');
        $this->assertFalse(auth('portal')->check());

        $url = $k->issueSetupLink();
        $this->travel(CustomerContact::LINK_HOURS + 1)->hours();
        $this->get($url)->assertSee('Link expired');
        $this->get('/portal/set-password/not-a-token')->assertNotFound();
    }

    public function test_guest_redirected_and_staff_cannot_use_portal(): void
    {
        $this->get('/portal')->assertRedirect(route('portal.login'));
        $this->get('/portal/statement.pdf')->assertRedirect(route('portal.login'));
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/portal/orders')->assertRedirect(route('portal.login'));
    }

    public function test_catalog_is_compliance_filtered_with_reasons_and_hides_archived_inactive(): void
    {
        $in = $this->contact('CTEST-IN');
        Product::where('sku', 'TST-GEN-001')->first()->archive();
        $inactive = Product::where('active', true)->where('sku', '!=', 'TST-GEN-001')->where('sku', '!=', 'TST-KRT-001')->first();
        $inactive->update(['active' => false]);
        $this->portal($in);
        $krt = Product::where('sku', 'TST-KRT-001')->first();
        Livewire::test(Catalog::class)
            ->assertSee('TST-KRT-001')->assertSee('Unavailable:')->assertSee('banned in IN')
            ->assertDontSee('TST-GEN-001')->assertDontSee($inactive->sku)
            ->call('add', $krt->id)->assertSet('cart', [])->assertSee('Not available');
        $this->get('/portal')->assertOk()->assertSee('Customer portal');
    }

    public function test_cart_submit_creates_confirmed_portal_order_with_customer_price_and_ship_to_restriction(): void
    {
        $ky = Customer::where('code', 'CTEST-KY')->first();
        $second = ShipTo::create(['customer_id' => $ky->id, 'name' => 'Second store', 'state' => 'KY', 'city' => 'Paris']);
        $k = $this->contact('CTEST-KY', ['ship_to_id' => $second->id]);
        $this->portal($k);
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $lw = Livewire::test(Catalog::class)->set('fulfillment', 'delivery')->assertSee('Second store')->assertDontSee('Main store')
            ->call('add', $p->id)->call('add', $p->id)->set('showCart', true)->assertSee('Submit order')
            ->call('setQty', $p->id, 3)->set('note', 'PO 778')->call('submit')->assertRedirect(route('portal.orders'));
        $so = SalesOrder::where('channel', 'portal')->firstOrFail();
        $this->assertSame('confirmed', $so->status->value);
        $this->assertSame($second->id, $so->ship_to_id);
        $this->assertSame('3.0000', $so->lines->first()->qty);
        $this->assertSame($p->price, $so->lines->first()->unit_price);
        $this->assertStringContainsString('PO 778', $so->notes);
        $this->assertSame([], session('portal.cart.'.$k->id));

        // tampering: a ship-to outside the restriction is refused
        $this->expectException(HttpException::class);
        app(PortalService::class)->submit($k, $ky->shipTos()->where('name', 'Main store')->first(), [$p->id => 1], null, 'delivery');
    }

    public function test_compliance_blocked_cart_cannot_submit_and_reorder_skips_blocked(): void
    {
        $in = $this->contact('CTEST-IN');
        $this->portal($in);
        $krt = Product::where('sku', 'TST-KRT-001')->first();
        $this->assertThrows(fn () => app(PortalService::class)->submit($in, $in->allowedShipTos()->first(), [$krt->id => 1]),
            WorkflowException::class, 'banned in IN');
        $this->assertSame(0, SalesOrder::where('channel', 'portal')->count());

        // past KY order -> reorder for KY contact fills the cart
        $ky = $this->contact('CTEST-KY');
        $old = $this->invoiced($ky->customer, 'TST-GEN-001', '4');
        auth('portal')->logout();
        $this->portal($ky);
        Livewire::test(Orders::class)->assertSee($old->number)->call('reorder', $old->id)->assertRedirect(route('portal.catalog', ['cart' => 1]));
        $gen = Product::where('sku', 'TST-GEN-001')->first();
        $this->assertEquals(4, (float) session('portal.cart.'.$ky->id)[$gen->id]);
        // other customers' orders are not reachable
        $other = $this->invoiced(Customer::where('code', 'CTEST-IL')->first());
        Livewire::test(Orders::class)->assertDontSee($other->number)->call('reorder', $other->id)->assertStatus(404);
    }

    public function test_balance_with_beginning_balance_invoice_and_statement_pdfs_and_admin_aging(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $k = $this->contact();
        $c = $k->customer;
        app(CustomerBalanceService::class)->setBeginningBalance($c, '100.00', now()->subDays(45)->toDateString(), $owner);
        $so = $this->invoiced($c->fresh());
        $total = (float) $so->total;
        $this->portal($k);
        Livewire::test(Orders::class)->assertSee(number_format(100 + $total, 2))->assertSee('Includes beginning balance $100.00');
        $this->get(route('portal.invoice', $so))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('portal.statement'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = $this->invoiced(Customer::where('code', 'CTEST-IL')->first());
        $this->get(route('portal.invoice', $other))->assertNotFound();

        $html = view('docs.statement', ['c' => $c->fresh(), 'company' => Company::current(), 'info' => 'X', 'invoices' => collect([$so]), 'payments' => collect(),
            'aging' => app(CustomerBalanceService::class)->aging($c->fresh()), 'opening' => '100.0000'])->render();
        $this->assertStringContainsString('Beginning balance', $html);
        $this->assertStringContainsString('31-60', $html);

        auth('portal')->logout();
        $this->actingAs($owner);
        $this->get(route('docs.statement', $c))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('docs.invoice', $so))->assertOk();
        Livewire::test(EditCustomer::class, ['record' => $c->id])->assertSee('Balance &amp; aging', false)->assertSee('100.00')->assertActionVisible('statement');
        $this->actingAs(User::factory()->create(['role' => 'warehouse']));
        $this->get(route('docs.statement', $c))->assertForbidden();
    }

    public function test_pay_not_configured_then_full_balance_via_gateway_posts_payments(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $k = $this->contact();
        $c = $k->customer;
        app(CustomerBalanceService::class)->setBeginningBalance($c, '50.00', now()->toDateString(), $owner);
        $so = $this->invoiced($c->fresh());
        $this->portal($k);
        Livewire::test(Pay::class)->assertSee('Online payments are not configured yet');

        $this->fakeGateway();
        $this->get('/portal/pay')->assertOk()->assertSee('jstest.authorize.net/v1/Accept.js', false);
        Livewire::test(Pay::class)->assertSee('sent directly to Authorize.net')->call('pay', 'COMMON.ACCEPT.INAPP.PAYMENT', 'tok', 'card', 'Card ••1111')
            ->assertSet('error', null)->assertSee('Beginning balance')->assertSee($so->number);
        $this->assertSame('0.0000', $so->fresh()->balance());
        $this->assertSame('0.0000', app(CustomerBalanceService::class)->openingRemaining($c->fresh()));
        $this->assertSame(2, Payment::where('customer_contact_id', $k->id)->count());
        $this->assertSame('0.0000', $c->fresh()->balance());
        $this->assertCount(2, app(PaymentGateway::class)->charges);
    }

    public function test_pay_single_invoice_only(): void
    {
        $k = $this->contact();
        $a = $this->invoiced($k->customer);
        $b = $this->invoiced($k->customer);
        $this->fakeGateway();
        $this->portal($k);
        Livewire::withQueryParams(['invoice' => $a->id])->test(Pay::class)->call('pay', 'd', 'v', 'card', 'Card ••1111')->assertSet('error', null);
        $this->assertSame('0.0000', $a->fresh()->balance());
        $this->assertSame($b->total, $b->fresh()->balance());
    }

    public function test_suspension_and_inactive_contact_block_portal(): void
    {
        $k = $this->contact();
        $this->portal($k);
        $this->get('/portal')->assertOk();
        Company::current()->update(['suspended' => true]);
        $this->get('/portal')->assertStatus(403)->assertSee('Portal unavailable');
        $this->assertFalse(auth('portal')->check());
        Livewire::test(Login::class)->set('email', $k->email)->set('password', 'Secret12345')->call('login')->assertHasErrors('email');
        Company::current()->update(['suspended' => false]);

        $this->portal($k);
        $k->update(['active' => false]);
        $this->get('/portal/orders')->assertRedirect(route('portal.login'));
        $this->assertFalse(auth('portal')->check());
    }
}
