<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/** A customer's portal login (guard "portal"). Never emailed: admins copy a one-time set-password link. */
class CustomerContact extends Authenticatable
{
    public const LINK_HOURS = 72;

    protected $guarded = ['id', 'password', 'setup_token_hash', 'remember_token'];

    protected $hidden = ['password', 'remember_token', 'setup_token_hash'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'password' => 'hashed', 'setup_token_expires_at' => 'datetime', 'last_login_at' => 'datetime'];
    }

    public function setEmailAttribute($v): void
    {
        $this->attributes['email'] = mb_strtolower(trim((string) $v));
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
        return ShipTo::where('customer_id', $this->customer_id)->when($this->ship_to_id, fn ($q) => $q->where('id', $this->ship_to_id))->orderBy('name')->get();
    }

    /** Issue a new one-time set-password link (invalidates any previous link). Returns the full URL to show the admin. */
    public function issueSetupLink(): string
    {
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

    public function canUsePortal(): bool
    {
        return $this->active && $this->password !== null && ($this->customer?->active ?? false) && ! Company::isSuspended();
    }
}
