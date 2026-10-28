<?php

namespace Tests\Feature;

use App\Accounting\DunningMailer;
use App\Accounting\DunningService;
use App\Audit\Audit;
use App\Filament\Pages\Dunning;
use App\Filament\Pages\Settings;
use App\Mail\DunningNoticeMail;
use App\Mailing\MailConfig;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DunningNotice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 0.20.18: automatic dunning email + SMTP settings editable in Company Settings.
 * Every credential below is a made-up test value; nothing real is ever used.
 */
class Phase2018DunningEmailTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Test-Only-Pw-9f3a1c';

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = Warehouse::where('code', 'MAIN')->first();
    }

    /** A server whose mailer "delivers" (Mail::fake stands in for the transport). */
    private function mailConfigured(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test.invalid']);
    }

    /** A customer with one invoice 40 days old on Net 15 (level 1 overdue). */
    private function overdue(array $attrs = []): Customer
    {
        $p = Product::factory()->create(['price' => '10', 'cost' => '1']);
        $po = PurchaseOrder::create(['number' => 'PO-T'.uniqid(), 'vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $po->lines()->create(['product_id' => $p->id, 'qty' => '50', 'unit_cost' => '1']);
        app(PurchaseOrderService::class)->receive($po->fresh());
        $c = Customer::factory()->create(array_merge(['terms' => 'Net 15', 'email' => 'ap@store.test'], $attrs));
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['number' => 'SO-T'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $s->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => '3', 'unit_price' => $p->price]);
        $svc = app(SalesOrderService::class);
        $so = $svc->confirm($so->fresh());
        $svc->pick($so);
        $svc->deliver($so);
        $svc->invoice($so)->fresh()->update(['invoiced_at' => now()->subDays(40)]);

        return $c;
    }

    private function contact(Customer $c, string $name, ?string $email, bool $billing = false, bool $active = true)
    {
        return $c->contacts()->create(['name' => $name, 'email' => $email, 'is_billing_contact' => $billing, 'active' => $active]);
    }

    private function go(): array
    {
        return app(DunningMailer::class)->runDaily();
    }

    // ---------- toggle / gating ----------

    public function test_auto_email_defaults_off_and_nothing_is_sent_or_created(): void
    {
        Mail::fake();
        $this->mailConfigured();
        $this->overdue();
        $this->assertFalse(DunningMailer::enabled());
        $r = $this->go();
        $this->assertNotNull($r['skipped']);
        $this->assertSame(0, DunningNotice::count());
        Mail::assertNothingSent();
    }

    public function test_not_configured_server_sends_nothing_and_page_keeps_the_notice(): void
    {
        Mail::fake();
        $this->overdue();
        DunningMailer::setEnabled(true);
        $this->assertTrue(Dunning::emailNotConfigured()); // phpunit mailer = array
        $r = $this->go();
        $this->assertStringContainsString('not configured', (string) $r['skipped']);
        Mail::assertNothingSent();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/admin/accounting/dunning')->assertOk()->assertSee('Email is not configured')->assertSee('email-not-configured', false);
    }

    // ---------- sending ----------

    public function test_sends_notice_with_pdf_attachment_to_billing_contact_and_logs_on_customer(): void
    {
        Mail::fake();
        $this->mailConfigured();
        $c = $this->overdue();
        $this->contact($c, 'Pat Pay', 'Billing@Store.test', true);
        $this->contact($c, 'Other', 'other@store.test');
        DunningMailer::setEnabled(true);

        $r = $this->go();
        $this->assertSame(1, $r['sent']);
        Mail::assertSent(DunningNoticeMail::class, function (DunningNoticeMail $m) {
            $this->assertTrue($m->hasTo('billing@store.test'));
            $this->assertFalse($m->hasTo('other@store.test'));
            $this->assertFalse($m->hasTo('ap@store.test'));
            $att = $m->attachments();
            $this->assertCount(1, $att);

            return true;
        });
        $n = DunningNotice::first();
        $this->assertSame('sent', $n->email_status);
        $this->assertSame('billing@store.test', $n->email_to);
        $this->assertNotNull($n->emailed_at);
        $log = ActivityLog::where('event', 'dunning_email')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('billing@store.test', (string) $log->description);
        $this->assertStringContainsString('System (scheduled dunning email)', json_encode($log->new_values));
        $this->assertSame((string) $c->id, (string) $log->subject_id);
    }

    public function test_pdf_attachment_is_a_real_pdf(): void
    {
        $this->overdue();
        $svc = app(DunningService::class);
        $n = $svc->run(now()->toDateString(), null)->notices()->first();
        $mail = new DunningNoticeMail($n, $svc->noticesPdf([$n], now()->toDateString()), null);
        $this->assertCount(1, $mail->attachments());
        $this->assertStringStartsWith('%PDF', $svc->noticesPdf([$n], now()->toDateString()));
    }

    public function test_recipient_order_billing_then_customer_then_first_contact(): void
    {
        $c = Customer::factory()->create(['email' => 'cust@store.test']);
        $this->assertSame(['cust@store.test'], DunningMailer::recipients($c));
        $this->contact($c, 'A', 'a@store.test');
        $this->assertSame(['cust@store.test'], DunningMailer::recipients($c->fresh()));
        $this->contact($c, 'B', 'b@store.test', true);
        $this->contact($c, 'Gone', 'gone@store.test', true, false); // inactive
        $this->assertSame(['b@store.test'], DunningMailer::recipients($c->fresh()));
        $d = Customer::factory()->create(['email' => null]);
        $this->assertSame([], DunningMailer::recipients($d));
        $this->contact($d, 'First', 'first@store.test');
        $this->contact($d, 'Second', 'second@store.test');
        $this->assertSame(['first@store.test'], DunningMailer::recipients($d->fresh()));
        $e = Customer::factory()->create(['email' => 'not-an-email']);
        $this->assertSame([], DunningMailer::recipients($e));
    }

    public function test_same_stage_is_never_emailed_twice(): void
    {
        Mail::fake();
        $this->mailConfigured();
        $c = $this->overdue();
        DunningMailer::setEnabled(true);
        $this->assertSame(1, $this->go()['sent']);
        $this->go(); // same day again
        Mail::assertSent(DunningNoticeMail::class, 1);
        // a later run after the repeat window makes a new notice for the same stage: marked duplicate, not sent
        DunningNotice::query()->update(['created_at' => now()->subDays(20)]);
        $r = $this->go();
        $this->assertSame(1, $r['created']);
        $this->assertSame(1, $r['duplicate']);
        Mail::assertSent(DunningNoticeMail::class, 1);
        $this->assertSame('duplicate', DunningNotice::orderByDesc('id')->first()->email_status);
        $this->assertSame($c->id, DunningNotice::first()->customer_id);
    }

    public function test_drafts_from_before_it_was_switched_on_are_not_sent(): void
    {
        Mail::fake();
        $this->mailConfigured();
        $this->overdue();
        app(DunningService::class)->run(now()->toDateString(), null); // an old draft
        DunningNotice::query()->update(['created_at' => now()->subDays(2)]);
        DunningMailer::setEnabled(true);
        $r = $this->go();
        $this->assertSame(0, $r['sent']);
        Mail::assertNothingSent();
        $this->assertNull(DunningNotice::first()->email_status);
    }

    public function test_no_email_and_opted_out_go_to_the_print_list(): void
    {
        Mail::fake();
        $this->mailConfigured();
        $this->overdue(['name' => 'No Mail Co', 'email' => null]);
        $this->overdue(['name' => 'Opt Out Co', 'dunning_email_optout' => true]);
        DunningMailer::setEnabled(true);
        $r = $this->go();
        $this->assertSame(2, $r['print']);
        Mail::assertNothingSent();
        $this->assertSame(['no_email', 'opted_out'], DunningNotice::orderBy('id')->pluck('email_status')->all());
        $this->assertSame(2, DunningMailer::printList()->count());
        // the Dunning page lists the count and the PDF action marks them printed
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/admin/accounting/dunning')->assertOk()->assertSee('2 notice(s) on the print list');
        Livewire::test(Dunning::class)->callAction('print_list')->assertHasNoActionErrors();
        $this->assertSame(0, DunningMailer::printList()->count());
        $this->assertSame(2, DunningNotice::whereNotNull('printed_at')->count());
    }

    public function test_failure_is_logged_without_credentials_retried_next_run_and_then_goes_to_print_list(): void
    {
        $this->mailConfigured();
        config(['mail.mailers.smtp.password' => self::PW, 'mail.mailers.smtp.username' => 'user@test.invalid']);
        $c = $this->overdue();
        DunningMailer::setEnabled(true);
        Mail::shouldReceive('forgetMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Auth failed for user@test.invalid with '.self::PW));

        $r = $this->go();
        $this->assertSame(1, $r['failed']);
        $n = DunningNotice::first();
        $this->assertSame('failed', $n->email_status);
        $this->assertSame(1, $n->email_attempts);
        $this->assertStringNotContainsString(self::PW, (string) $n->email_error);
        $this->assertStringNotContainsString(self::PW, json_encode(ActivityLog::all()->toArray()));
        $this->assertSame(0, DunningMailer::printList()->count());

        for ($i = 2; $i < DunningMailer::MAX_ATTEMPTS; $i++) { // retried on every run
            $this->assertSame(1, $this->go()['failed']);
        }
        $this->assertSame(1, $this->go()['print']); // the last attempt hands it to the print list
        $this->assertSame(DunningMailer::MAX_ATTEMPTS, $n->fresh()->email_attempts);
        $this->assertSame(1, DunningMailer::printList()->count());
        $this->assertSame(0, $this->go()['failed']); // no more retries
        $this->assertSame($c->id, $n->customer_id);
    }

    public function test_failed_notice_is_retried_and_sent_when_the_server_recovers(): void
    {
        $this->mailConfigured();
        $this->overdue();
        DunningMailer::setEnabled(true);
        Mail::shouldReceive('forgetMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('connection refused'));
        $this->assertSame(1, $this->go()['failed']);
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        Mail::fake();
        $this->assertSame(1, $this->go()['sent']);
        $this->assertSame('sent', DunningNotice::first()->email_status);
        $this->assertSame(2, DunningNotice::first()->email_attempts);
        Mail::assertSent(DunningNoticeMail::class, 1);
    }

    public function test_artisan_command_and_schedule(): void
    {
        Mail::fake();
        $this->assertSame(0, Artisan::call('dunning:send'));
        $this->assertStringContainsString('off', Artisan::output());
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'dunning:send'));
        // schedule is only registered when .env exists (production); the command itself is always available
        $this->assertTrue($events->count() <= 1);
        $this->assertTrue(array_key_exists('dunning:send', Artisan::all()));
    }

    public function test_customer_opt_out_toggle_and_billing_contact_flag_exist_in_the_forms(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $c = Customer::factory()->create();
        Livewire::test(\App\Filament\Resources\CustomerResource\Pages\EditCustomer::class, ['record' => $c->getKey()])
            ->fillForm(['dunning_email_optout' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($c->fresh()->dunning_email_optout);
        $this->assertTrue((bool) $this->contact($c, 'X', 'x@store.test', true)->fresh()->is_billing_contact);
    }

    // ---------- MailConfig ----------

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner']);
    }

    private function emailForm(array $over = []): array
    {
        return array_merge(['mail_host' => 'smtp.test.invalid', 'mail_port' => 465, 'mail_encryption' => 'ssl', 'mail_username' => 'notices@test.invalid',
            'mail_password' => self::PW, 'mail_from_address' => 'notices@test.invalid', 'mail_from_name' => 'Test Distribution', 'dunning_auto_email' => true], $over);
    }

    public function test_db_settings_win_over_env_and_blank_host_falls_back(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env.test.invalid', 'mail.from.address' => 'env@test.invalid', 'mail.from.name' => 'Env Name']);
        MailConfig::apply();
        $this->assertSame('log', config('mail.default')); // nothing saved: .env stands
        $this->assertSame('env.test.invalid', config('mail.mailers.smtp.host'));

        MailConfig::save($this->emailForm());
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.test.invalid', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(self::PW, config('mail.mailers.smtp.password'));
        $this->assertSame('notices@test.invalid', config('mail.from.address'));
        $this->assertSame('Test Distribution', config('mail.from.name'));

        // fresh process: boot applies the saved values
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env.test.invalid']);
        MailConfig::apply();
        $this->assertSame('smtp.test.invalid', config('mail.mailers.smtp.host'));

        // clearing the host makes .env apply again (saved SMTP values removed)
        MailConfig::save(['mail_host' => '', 'mail_from_address' => '', 'mail_from_name' => '']);
        $this->assertNull(MailConfig::stored()['mail_username']);
        $this->assertFalse(MailConfig::passwordIsSet());
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env.test.invalid', 'mail.from.address' => 'env@test.invalid']);
        MailConfig::apply();
        $this->assertSame('log', config('mail.default'));
        $this->assertSame('env.test.invalid', config('mail.mailers.smtp.host'));
        $this->assertSame('env@test.invalid', config('mail.from.address'));
    }

    public function test_encryption_modes_map_to_the_transport_options(): void
    {
        MailConfig::save($this->emailForm(['mail_encryption' => 'tls', 'mail_port' => 587]));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        MailConfig::save($this->emailForm(['mail_encryption' => 'none', 'mail_port' => 25, 'mail_password' => null]));
        $this->assertFalse(config('mail.mailers.smtp.auto_tls'));
        $this->assertFalse(config('mail.mailers.smtp.require_tls'));
        MailConfig::save($this->emailForm(['mail_encryption' => 'ssl', 'mail_port' => null, 'mail_password' => null]));
        $this->assertSame(465, config('mail.mailers.smtp.port')); // ssl default port
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(self::PW, config('mail.mailers.smtp.password')); // blank password kept it
        // the real mailer builds an smtps transport for 465
        $t = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
        $this->assertStringContainsString('smtps://', (string) $t);
    }

    public function test_password_is_encrypted_at_rest(): void
    {
        MailConfig::save($this->emailForm());
        $raw = DB::table('settings')->where('key', 'mail_password')->value('value');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::PW, (string) $raw);
        $this->assertSame(self::PW, \Illuminate\Support\Facades\Crypt::decryptString($raw));
        $this->assertTrue(MailConfig::passwordIsSet());
        // the dump of the settings table never contains it
        $this->assertStringNotContainsString(self::PW, json_encode(DB::table('settings')->get()));
    }

    public function test_audit_entry_has_changes_but_never_the_password(): void
    {
        MailConfig::save($this->emailForm());
        $row = ActivityLog::where('event', 'mail_settings')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('password replaced', (string) $row->description);
        $this->assertStringContainsString('smtp.test.invalid', json_encode($row->new_values));
        $this->assertStringNotContainsString(self::PW, json_encode(ActivityLog::all()->toArray()));
        // second save with same values and blank password: no new mail_settings entry
        MailConfig::save($this->emailForm(['mail_password' => '']));
        $this->assertSame(1, ActivityLog::where('event', 'mail_settings')->count());
        // changing just the host logs again, still no password
        MailConfig::save($this->emailForm(['mail_host' => 'smtp2.test.invalid', 'mail_password' => '']));
        $this->assertSame(2, ActivityLog::where('event', 'mail_settings')->count());
        $this->assertStringNotContainsString(self::PW, json_encode(ActivityLog::all()->toArray()));
        $this->assertSame('********', Audit::MASK);
    }

    public function test_email_tab_saves_through_the_form_and_the_password_never_comes_back(): void
    {
        $this->actingAs($this->owner());
        $t = Livewire::test(Settings::class)->assertSee('Outgoing email (SMTP)')->assertSee('No password saved.');
        $t->fillForm($this->emailForm())->call('save')->assertHasNoFormErrors();
        $this->assertTrue(DunningMailer::enabled());
        $this->assertSame('smtp.test.invalid', MailConfig::stored()['mail_host']);
        $this->assertSame(self::PW, MailConfig::stored()['mail_password']);
        $this->assertNotNull(DunningMailer::since());
        $this->assertSame(1, ActivityLog::where('event', 'dunning_auto_email')->count());
        // not in the response after saving
        $this->assertStringNotContainsString(self::PW, $t->html());
        $this->assertNull($t->get('data.mail_password'));
        // a fresh page load shows "set", never the value
        $page = $this->get(Settings::getUrl());
        $page->assertOk()->assertSee('A password is set.');
        $this->assertStringNotContainsString(self::PW, $page->getContent());
        $this->assertStringNotContainsString(self::PW, json_encode(Livewire::test(Settings::class)->get('data')));
        // blank password on a later save keeps the stored one
        Livewire::test(Settings::class)->fillForm(['mail_from_name' => 'Renamed'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(self::PW, MailConfig::stored()['mail_password']);
        $this->assertSame('Renamed', MailConfig::stored()['mail_from_name']);
        $this->assertStringNotContainsString(self::PW, json_encode(ActivityLog::all()->toArray()));
    }

    public function test_email_form_validation_does_not_echo_the_password(): void
    {
        $this->actingAs($this->owner());
        $t = Livewire::test(Settings::class)->fillForm($this->emailForm(['mail_port' => 70000, 'mail_from_address' => 'nope']))->call('save')
            ->assertHasFormErrors(['mail_port', 'mail_from_address']);
        $this->assertStringNotContainsString(self::PW, $t->html());
        $this->assertNull(MailConfig::stored()['mail_host']);
    }

    public function test_email_tab_and_test_action_are_owner_or_brandworx_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertFalse(Settings::canSeeSecrets());
        $html = Livewire::test(Settings::class)->html();
        $this->assertStringNotContainsString('Outgoing email (SMTP)', $html);
        $t = Livewire::test(Settings::class);
        $t->assertActionHidden('test_email');
        // a forged mail_* payload from a non-owner is ignored
        $t->set('data.mail_host', 'evil.test.invalid')->call('save');
        $this->assertNull(MailConfig::stored()['mail_host']);
        $this->assertFalse(DunningMailer::enabled());
        $this->actingAs($this->owner());
        Livewire::test(Settings::class)->assertActionVisible('test_email');
    }

    public function test_send_test_email_action(): void
    {
        Mail::fake();
        $this->actingAs($this->owner());
        MailConfig::save($this->emailForm());
        Livewire::test(Settings::class)->callAction('test_email', ['to' => 'me@test.invalid'])->assertHasNoActionErrors()->assertNotified('Test email sent');
    }

    public function test_send_test_email_refuses_when_no_mail_server(): void
    {
        $this->actingAs($this->owner());
        [$ok, $msg] = MailConfig::sendTest('me@test.invalid'); // phpunit mailer = array
        $this->assertFalse($ok);
        $this->assertStringContainsString('No mail server', $msg);
        Livewire::test(Settings::class)->callAction('test_email', ['to' => 'me@test.invalid'])->assertNotified('Test email failed');
    }

    public function test_send_test_failure_message_hides_credentials(): void
    {
        MailConfig::save($this->emailForm());
        Mail::shouldReceive('forgetMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('535 bad login notices@test.invalid / '.self::PW));
        [$ok, $msg] = MailConfig::sendTest('me@test.invalid');
        $this->assertFalse($ok);
        $this->assertStringNotContainsString(self::PW, $msg);
        $this->assertStringNotContainsString('notices@test.invalid /', $msg);
    }

    public function test_env_example_has_blank_mail_placeholders(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        foreach (['MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $k) {
            $this->assertMatchesRegularExpression('/^'.$k.'=$/m', $env, $k);
        }
        $this->assertMatchesRegularExpression('/^0\.20\.\d+$/', config('erp.version'));
    }
}
