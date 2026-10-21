<?php

namespace Tests\Feature;

use App\Audit\Audit;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\ChangePassword;
use App\Filament\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\PasswordAdmin;
use App\Services\SalesOrderService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class Phase16SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner', 'active' => true, 'name' => 'Olivia Owner']);
    }

    public function test_create_update_delete_logged_with_who_ip_and_old_new(): void
    {
        $this->actingAs($this->owner);
        $c = Customer::create(['code' => 'AUD1', 'name' => 'Audit Shop', 'terms' => 'Net 15']);
        $c->update(['terms' => 'Net 30']);
        $c->delete();
        $rows = ActivityLog::where('subject_type', Customer::class)->where('subject_id', $c->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $rows->pluck('event')->all());
        $u = $rows[1];
        $this->assertSame(['terms' => 'Net 15'], $u->old_values);
        $this->assertSame(['terms' => 'Net 30'], $u->new_values);
        $this->assertSame($this->owner->id, $u->user_id);
        $this->assertSame('Olivia Owner', $u->user_name);
        $this->assertSame('127.0.0.1', $u->ip);
        $this->assertSame('AUD1', $u->subject_label);
        $this->assertSame('Audit Shop', $rows[2]->old_values['name']);
    }

    public function test_secrets_masked_and_role_and_password_changes_logged(): void
    {
        $this->actingAs($this->owner);
        $u = User::factory()->create(['role' => 'sales', 'active' => true]);
        $u->update(['role' => 'warehouse', 'password' => Hash::make('a-new-long-password')]);
        $upd = ActivityLog::where('subject_id', $u->id)->where('subject_type', User::class)->where('event', 'updated')->first();
        $this->assertSame(Audit::MASK, $upd->new_values['password']);
        $this->assertSame(Audit::MASK, $upd->old_values['password']);
        $this->assertSame('warehouse', $upd->new_values['role']);
        $this->assertTrue(ActivityLog::where('event', 'role_changed')->where('subject_id', $u->id)->exists());
        $this->assertTrue(ActivityLog::where('event', 'password_changed')->where('subject_id', $u->id)->exists());
        Setting::put('authnet_transaction_key', 'SUPERSECRET123');
        $this->assertDatabaseMissing('activity_logs', ['new_values' => null, 'event' => 'never']);
        $this->assertFalse(ActivityLog::where('new_values', 'like', '%SUPERSECRET%')->exists());
        $this->assertTrue(ActivityLog::where('event', 'settings_changed')->exists());
        foreach (['pos_pin_hash', 'pos_pin_lookup', 'api_key', 'remember_token', 'portal_token'] as $k) {
            $this->assertTrue(Audit::isSecret($k) || in_array($k, Audit::IGNORE, true), $k);
        }
        foreach (['shipping_method', 'name', 'ship_to_id', 'terms'] as $k) {
            $this->assertFalse(Audit::isSecret($k), $k);
        }
    }

    public function test_key_actions_status_transitions_and_sign_logged(): void
    {
        $this->actingAs($this->owner);
        $c = Customer::where('code', 'CTEST-KY')->first();
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $so = SalesOrder::create(['number' => 'SO-AUD', 'customer_id' => $c->id, 'ship_to_id' => $c->shipTos()->value('id'),
            'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id'), 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_price' => $p->price]);
        $svc = app(SalesOrderService::class);
        $svc->confirm($so);
        $svc->deliver($so->fresh());
        $svc->invoice($so->fresh());
        $events = ActivityLog::where('subject_type', SalesOrder::class)->where('subject_id', $so->id)->pluck('event')->all();
        foreach (['created', 'confirmed', 'delivered', 'invoiced'] as $e) {
            $this->assertContains($e, $events);
        }
        $this->assertTrue(ActivityLog::where('event', 'posted')->exists()); // journal posted on invoice
    }

    public function test_logins_logouts_and_failed_logins_logged(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'active' => true, 'email' => 'login@example.test', 'password' => Hash::make('correct-horse-battery')]);
        Auth::attempt(['email' => 'login@example.test', 'password' => 'wrong']);
        Auth::attempt(['email' => 'login@example.test', 'password' => 'correct-horse-battery']);
        Auth::logout();
        $this->assertTrue(ActivityLog::where('event', 'login_failed')->where('description', 'like', '%login@example.test%')->exists());
        $this->assertSame($u->id, ActivityLog::where('event', 'login')->value('user_id'));
        $this->assertSame($u->id, ActivityLog::where('event', 'logout')->value('user_id'));
        $this->assertFalse(ActivityLog::where('description', 'like', '%wrong%')->exists());
    }

    public function test_log_is_read_only_in_model_and_ui(): void
    {
        $this->actingAs($this->owner);
        Customer::create(['code' => 'RO1', 'name' => 'RO']);
        $row = ActivityLog::latest('id')->first();
        $this->expectException(\LogicException::class);
        try {
            $row->update(['event' => 'x']);
        } finally {
            $this->assertFalse(ActivityLogResource::canCreate());
            $this->assertFalse(ActivityLogResource::canEdit($row));
            $this->assertFalse(ActivityLogResource::canDelete($row));
            $this->assertFalse(ActivityLogResource::canDeleteAny());
            $this->assertArrayNotHasKey('edit', ActivityLogResource::getPages());
            try {
                $row->delete();
            } catch (\LogicException) {
            }
            $this->assertNotNull(ActivityLog::find($row->id));
        }
    }

    public function test_activity_screen_filters_view_history_tab_and_access(): void
    {
        $this->actingAs($this->owner);
        $c = Customer::create(['code' => 'FLT1', 'name' => 'Filter Shop']);
        $c->update(['name' => 'Filter Shop 2']);
        $this->get('/admin/activity-log')->assertOk()->assertSee('FLT1')->assertSee('Powered by DistroSKU');
        $row = ActivityLog::where('event', 'updated')->where('subject_id', $c->id)->where('subject_type', Customer::class)->first();
        $this->get("/admin/activity-log/{$row->id}")->assertOk()->assertSee('Filter Shop 2')->assertSee('Filter Shop');
        Livewire::test(ListActivityLogs::class)
            ->filterTable('subject_type', Customer::class)->assertCanSeeTableRecords([$row])
            ->filterTable('event', ['deleted'])->assertCanNotSeeTableRecords([$row]);
        Livewire::test(ListActivityLogs::class)->filterTable('record', ['subject_id' => $c->id])->assertCanSeeTableRecords([$row]);
        Livewire::test(ListActivityLogs::class)->filterTable('user_id', $this->owner->id)->assertCanSeeTableRecords([$row]);
        Livewire::test(ListActivityLogs::class)->filterTable('user_id', $this->owner->id)->assertCanSeeTableRecords([$row]);
        $this->get("/admin/customers/{$c->id}/edit")->assertOk()->assertSee('History');
        Livewire::test(HistoryRelationManager::class, ['ownerRecord' => $c, 'pageClass' => EditCustomer::class])
            ->assertOk()->assertCanSeeTableRecords([$row]);
        $this->flushSession();
        $this->actingAs(User::where('role', 'sales')->first())->get('/admin/activity-log')->assertForbidden();
    }

    public function test_admin_sets_temporary_password_and_user_must_change(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'active' => true]);
        $this->actingAs($this->owner);
        Livewire::test(ListUsers::class)->callTableAction('resetPassword', $u, ['mode' => 'temp', 'password' => 'Temporary-Pass-99', 'force' => true])->assertHasNoTableActionErrors();
        $u->refresh();
        $this->assertTrue(Hash::check('Temporary-Pass-99', $u->password));
        $this->assertTrue($u->must_change_password);
        $this->assertTrue(ActivityLog::where('event', 'password_reset')->where('subject_id', $u->id)->where('user_id', $this->owner->id)->exists());
        $this->assertFalse(ActivityLog::where('new_values', 'like', '%Temporary-Pass-99%')->orWhere('description', 'like', '%Temporary-Pass-99%')->exists());

        $this->flushSession();
        $this->actingAs($u)->get('/admin')->assertRedirect('/admin/change-password');
        $this->get('/admin/change-password')->assertOk()->assertSee('reset by an administrator');
        Livewire::test(ChangePassword::class)->set('current', 'Temporary-Pass-99')->set('password', 'short')->set('password_confirmation', 'short')
            ->call('save')->assertHasErrors('password');
        Livewire::test(ChangePassword::class)->set('current', 'Temporary-Pass-99')->set('password', 'My-Own-Password-1')->set('password_confirmation', 'My-Own-Password-1')
            ->call('save')->assertHasNoErrors();
        $this->assertFalse($u->fresh()->must_change_password);
        $this->get('/admin')->assertOk();
    }

    public function test_reset_permissions_and_link_needs_mail(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $sales = User::where('role', 'sales')->first();
        $this->assertTrue(PasswordAdmin::canReset($admin, $sales));
        $this->assertFalse(PasswordAdmin::canReset($admin, $this->owner));
        $this->assertFalse(PasswordAdmin::canReset($sales, User::where('role', 'warehouse')->first()));
        $this->assertTrue(PasswordAdmin::canReset($this->owner, $admin));
        $this->expectExceptionObject(new WorkflowException('You cannot reset this user\'s password.'));
        try {
            app(PasswordAdmin::class)->setTemporary($admin, $this->owner, 'Whatever-Long-123');
        } finally {
            config(['mail.default' => 'log']);
            $this->assertFalse(PasswordAdmin::mailConfigured());
            try {
                app(PasswordAdmin::class)->sendLink($admin, $sales);
                $this->fail('link without mail');
            } catch (WorkflowException) {
            }
            config(['mail.default' => 'smtp', 'mail.from.address' => 'erp@example.test']);
            Notification::fake();
            app(PasswordAdmin::class)->sendLink($admin, $sales, true);
            Notification::assertSentTo($sales, ResetPassword::class);
            $this->assertTrue($sales->fresh()->must_change_password);
            $this->assertTrue(ActivityLog::where('event', 'password_reset_link')->exists());
        }
    }

    public function test_migration_reruns_and_short_index_names(): void
    {
        $m = include database_path('migrations/2026_11_05_000001_phase16_security_audit.php');
        $m->up();
        $m->up();
        foreach (['al_subject_idx', 'al_user_created_idx', 'al_event_created_idx', 'al_created_idx'] as $i) {
            $this->assertTrue(Schema::hasIndex('activity_logs', $i));
            $this->assertLessThanOrEqual(64, strlen($i));
        }
    }
}
