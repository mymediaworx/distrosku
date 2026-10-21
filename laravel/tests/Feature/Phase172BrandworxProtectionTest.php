<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\ActivityLog;
use App\Models\User;
use App\Security\PasswordAdmin;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class Phase172BrandworxProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $bwx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->bwx = User::factory()->create(['role' => 'brandworx_admin', 'active' => true, 'email' => 'bwx@example.test']);
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['role' => $role, 'active' => true]);
        $this->actingAs($u);

        return $u;
    }

    private function blocked(callable $fn): void
    {
        $before = ActivityLog::where('event', 'bwx_protect_blocked')->count();
        try {
            $fn();
            $this->fail('not blocked');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Only a BrandWorx admin', $e->getMessage());
        }
        $this->assertSame($before + 1, ActivityLog::where('event', 'bwx_protect_blocked')->count());
    }

    public function test_owner_and_admin_blocked_server_side_and_logged(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $this->as($role);
            $this->blocked(fn () => $this->bwx->fresh()->update(['active' => false]));
            $this->blocked(fn () => $this->bwx->fresh()->update(['role' => 'admin']));
            $this->blocked(fn () => $this->bwx->fresh()->update(['email' => 'x@example.test']));
            $this->blocked(fn () => $this->bwx->fresh()->forceFill(['password' => Hash::make('Another-pass-123')])->save());
            $this->blocked(fn () => $this->bwx->fresh()->delete());
            $this->blocked(fn () => app(PasswordAdmin::class)->setTemporary(auth()->user(), $this->bwx->fresh(), 'Temp-password-1234'));
            $this->blocked(fn () => User::whereKey($this->bwx->id)->get()->each->delete()); // bulk-style
            $f = $this->bwx->fresh();
            $this->assertTrue((bool) $f->active);
            $this->assertSame('brandworx_admin', $f->role->value);
            $this->assertSame('bwx@example.test', $f->email);
        }
        $log = ActivityLog::where('event', 'bwx_protect_blocked')->latest('id')->first();
        $this->assertSame($this->bwx->id, (int) $log->subject_id);
    }

    public function test_ui_hides_actions_for_owner(): void
    {
        $this->as('owner');
        $this->assertFalse(UserResource::canEdit($this->bwx));
        $this->assertFalse(UserResource::canDelete($this->bwx));
        $this->assertFalse(PasswordAdmin::canReset(auth()->user(), $this->bwx));
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('toggleActive', $this->bwx)
            ->assertTableActionHidden('resetPassword', $this->bwx)
            ->assertTableActionHidden('edit', $this->bwx);
    }

    public function test_another_brandworx_admin_can_change_everything(): void
    {
        $me = $this->as('brandworx_admin');
        $this->assertTrue(UserResource::canEdit($this->bwx));
        $this->assertTrue(PasswordAdmin::canReset($me, $this->bwx));
        app(PasswordAdmin::class)->setTemporary($me, $this->bwx->fresh(), 'Temp-password-1234');
        $this->bwx->fresh()->update(['email' => 'bwx2@example.test', 'active' => false]);
        $this->bwx->fresh()->update(['role' => 'admin']);
        $this->assertSame('admin', $this->bwx->fresh()->role->value);
        $this->bwx->fresh()->delete();
        $this->assertNull(User::find($this->bwx->id));
    }

    public function test_owner_can_still_manage_normal_users(): void
    {
        $this->as('owner');
        $s = User::factory()->create(['role' => 'sales', 'active' => true]);
        $s->update(['active' => false, 'email' => 's2@example.test']);
        $this->assertFalse((bool) $s->fresh()->active);
    }

    public function test_console_context_not_blocked(): void
    {
        $this->bwx->update(['active' => false]);
        $this->assertFalse((bool) $this->bwx->fresh()->active);
    }
}
