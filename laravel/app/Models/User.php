<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasActivity;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    protected static function booted(): void
    {
        static::saving(fn (User $u) => \App\Security\BrandworxProtection::guardSave($u));
        static::deleting(fn (User $u) => \App\Security\BrandworxProtection::guardDelete($u));
    }

    use HasActivity;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'active'];

    protected $hidden = ['password', 'remember_token', 'pos_pin_hash', 'pos_pin_lookup'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->active) {
            return false;
        }

        // Suspended company account: only the BrandWorx platform admin can sign in.
        return ! Company::isSuspended() || $this->hasRole('brandworx_admin');
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $r) {
            if ($this->role === (is_string($r) ? Role::from($r) : $r)) {
                return true;
            }
        }

        return false;
    }

    /** Module permission check (config/erp.php permissions). Unknown modules are full-roles only. */
    public function canModule(string $module, string $ability = 'view'): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        $roles = config("erp.permissions.$module.$ability", []);

        return in_array($this->role?->value, $roles, true);
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role?->value, config('erp.full_roles', ['owner', 'admin', 'brandworx_admin']), true);
    }
}
