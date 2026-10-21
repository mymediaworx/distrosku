<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Phase173BrandworxRoleAssignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['role' => $role, 'active' => true]);
        $this->actingAs($u);

        return $u;
    }

    private function logs(): int
    {
        return ActivityLog::where('event', 'bwx_protect_blocked')->count();
    }

    public function test_role_option_hidden_from_owner_and_admin(): void
    {
        foreach (['owner', 'admin'] as $r) {
            $this->as($r);
            $this->assertArrayNotHasKey('brandworx_admin', UserResource::roleOptions());
        }
        $this->as('brandworx_admin');
        $this->assertArrayHasKey('brandworx_admin', UserResource::roleOptions());
    }

    public function test_owner_cannot_create_or_promote_to_brandworx_admin_any_route(): void
    {
        foreach (['owner', 'admin'] as $r) {
            $this->as($r);
            $n = $this->logs();
            try {
                User::create(['name' => 'X', 'email' => "x-$r@example.test", 'password' => 'Long-password-123', 'role' => 'brandworx_admin', 'active' => true]);
                $this->fail('create allowed');
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('assign the brandworx_admin role', $e->getMessage());
            }
            $s = User::factory()->create(['role' => 'sales']);
            try {
                $s->update(['role' => 'brandworx_admin']);
                $this->fail('promote allowed');
            } catch (WorkflowException) {
            }
            try { // bulk-style / import-style loop
                User::whereKey($s->id)->get()->each->update(['role' => 'brandworx_admin']);
                $this->fail('bulk allowed');
            } catch (WorkflowException) {
            }
            $this->assertSame('sales', $s->fresh()->role->value);
            $this->assertSame($n + 3, $this->logs());
            $this->assertSame(0, User::where('email', "x-$r@example.test")->count());
        }
    }

    public function test_filament_create_form_rejects_forged_role(): void
    {
        $this->as('owner');
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Forged', 'email' => 'forged@example.test', 'password' => 'Long-password-123', 'role' => 'brandworx_admin', 'active' => true])
            ->call('create')->assertHasFormErrors(['role']);
        $this->assertSame(0, User::where('email', 'forged@example.test')->count());
    }

    public function test_brandworx_admin_can_assign_role(): void
    {
        $this->as('brandworx_admin');
        $s = User::factory()->create(['role' => 'sales']);
        $s->update(['role' => 'brandworx_admin']);
        $this->assertSame('brandworx_admin', $s->fresh()->role->value);
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New BW', 'email' => 'nbw@example.test', 'password' => 'Long-password-123', 'role' => 'brandworx_admin', 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('brandworx_admin', User::where('email', 'nbw@example.test')->first()->role->value);
    }

    public function test_bwx_name_protected_from_non_bwx(): void
    {
        $bw = User::factory()->create(['role' => 'brandworx_admin', 'name' => 'BW One']);
        $this->as('owner');
        $n = $this->logs();
        try {
            $bw->update(['name' => 'Renamed']);
            $this->fail('rename allowed');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('rename', $e->getMessage());
        }
        $this->assertSame('BW One', $bw->fresh()->name);
        $this->assertSame($n + 1, $this->logs());
        $this->as('brandworx_admin');
        $bw->fresh()->update(['name' => 'Renamed']);
        $this->assertSame('Renamed', $bw->fresh()->name);
    }

    public function test_console_seeding_still_works(): void
    {
        $u = User::create(['name' => 'Seed BW', 'email' => 'seedbw@example.test', 'password' => 'Long-password-123', 'role' => 'brandworx_admin', 'active' => true]);
        $this->assertSame('brandworx_admin', $u->fresh()->role->value);
    }
}
