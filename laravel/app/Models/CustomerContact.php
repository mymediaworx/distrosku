<?php

namespace App\Models;

use App\Exceptions\WorkflowException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/** A customer's contact; optional portal login (guard "portal") when is_portal_contact + email. */
class CustomerContact extends Authenticatable
{
    public const LINK_HOURS = 72;

    protected $guarded = ['id', 'password', 'setup_token_hash', 'remember_token'];

    protected $hidden = ['password', 'remember_token', 'setup_token_hash'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_portal_contact' => 'boolean',
            'password' => 'hashed',
            'setup_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomerContact $c) {
            $email = trim((string) ($c->email ?? ''));
            if ($email === '') {
                $c->attributes['email'] = null;
                $c->is_portal_contact = false;
            }
            if ($c->is_portal_contact && blank($c->email)) {
                throw new WorkflowException('Portal access requires an email address.');
            }
        });
    }

    public function setEmailAttribute($v): void
    {
        $v = trim((string) $v);
        $this->attributes['email'] = $v === '' ? null : mb_strtolower($v);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function shipTo()
    {
        return $this->belongsTo(ShipTo::class);
    }

    /** Ship-tos this contact may order for (the restricted one, or all of the customer's). */
    public function allowedShipTos()
    {
        return ShipTo::where('customer_id', $this->customer_id)->when($this->ship_to_id, fn ($q) => $q->where('id', $this->ship_to_id))->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** Issue a new one-time set-password link (invalidates any previous link). Returns the full URL to show the admin. */
    public function issueSetupLink(): string
    {
        if (! $this->canInviteToPortal()) {
            throw new WorkflowException('Portal access requires an email address and the portal-contact flag.');
        }
        $token = Str::random(48);
        $this->forceFill(['setup_token_hash' => hash('sha256', $token), 'setup_token_expires_at' => now()->addHours(self::LINK_HOURS)])->save();

        return route('portal.set-password', ['token' => $token]);
    }

    public static function findBySetupToken(string $token): ?self
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }
        $c = static::where('setup_token_hash', hash('sha256', $token))->first();

        return ($c && $c->setup_token_expires_at?->isFuture()) ? $c : null;
    }

    public function setPasswordFromLink(string $password): void
    {
        $this->forceFill(['password' => $password, 'setup_token_hash' => null, 'setup_token_expires_at' => null, 'remember_token' => Str::random(60)])->save();
    }

    public function canInviteToPortal(): bool
    {
        return $this->is_portal_contact && filled($this->email);
    }

    public function canUsePortal(): bool
    {
        return $this->is_portal_contact
            && filled($this->email)
            && $this->active
            && $this->password !== null
            && ($this->customer?->active ?? false)
            && ! Company::isSuspended();
    }
}
